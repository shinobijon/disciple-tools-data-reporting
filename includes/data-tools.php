<?php


class DT_Data_Reporting_Tools
{
    public static function get_contacts( $flatten = false, $filter = null ) {
        $filter = $filter ?? array();

        // Build contact generations
        // taken from [dt-theme]/dt-metrics/counters/counter-baptism.php::save_all_contact_generations
        $raw_baptism_generation_list = Disciple_Tools_Counter_Baptism::query_get_all_baptism_connections();
        $all_baptisms = Disciple_Tools_Counter_Baptism::build_baptism_generation_counts( $raw_baptism_generation_list );
        $contact_generations = array();
        foreach ( $all_baptisms as $baptism_generation ){
            $generation = $baptism_generation["generation"];
            $baptisms = $baptism_generation["ids"];
            foreach ( $baptisms as $contact ){
                $contact_generations[$contact] = $generation;
            }
        }

        $contacts = DT_Posts::list_posts('contacts', $filter);
        dt_write_log(sizeof($contacts['posts']) . ' of ' . $contacts['total']);
//        dt_write_log(json_encode($contacts['posts'][0]));
        if ( !isset($filter['limit']) ) {
            // if total is greater than length, recursively get more
            while (sizeof($contacts['posts']) < $contacts['total']) {
                $filter['offset'] = sizeof($contacts['posts']);
                $next_contacts = DT_Posts::list_posts('contacts', $filter);
                $contacts['posts'] = array_merge($contacts['posts'], $next_contacts['posts']);
                dt_write_log('adding ' . sizeof($next_contacts['posts']));
                dt_write_log(sizeof($contacts['posts']) . ' of ' . $contacts['total']);
            }
        }
        $items = [];

        $post_settings = apply_filters( "dt_get_post_type_settings", [], 'contacts' );
        $fields = $post_settings["fields"];
        $excluded_fields = ['tasks', 'facebook_data'];
        $base_url = self::get_current_site_base_url();

        foreach ($contacts['posts'] as $index => $result) {
            $contact = [
                'ID' => $result['ID'],
                'Created' => $result['post_date'],
            ];
            foreach ( $fields as $field_key => $field ){
                // skip if field is hidden
                if ( isset( $field['hidden'] ) && $field['hidden'] == true ) {
                    continue;
                }
                // skip if in list of excluded fields
                if ( in_array( $field_key, $excluded_fields ) ) {
                    continue;
                }

                $type = $field['type'];
                $fieldValue = null;
                if (key_exists($field_key, $result)) {
                    switch ($type) {
                        case 'key_select':
                            $fieldValue = self::getLabel($result, $field_key);
                            break;
                        case 'multi_select':
                            $fieldValue = $flatten ? implode(",", $result[$field_key]) : $result[$field_key];
                            break;
                        case 'user_select':
                            $fieldValue = $result[$field_key]['id'];
                            break;
                        case 'date':
                            $fieldValue = date("Y-m-d H:i:s", $result[$field_key]['timestamp']);
                            break;
                        case 'location':
                            $location_ids = array_map(function ( $location ) { return $location['label']; }, $result[$field_key]);
                            $fieldValue = $flatten ? implode(",", $location_ids) : $location_ids;
                            break;
                        case 'connection':
                            $connection_ids = array_map(function ( $connection ) { return $connection['ID']; }, $result[$field_key]);
                            $fieldValue = $flatten ? implode(",", $connection_ids) : $connection_ids;
                            break;
                        default:
                            $fieldValue = $result[$field_key];
                            if ( is_array($fieldValue) ) {
                                $fieldValue = json_encode($fieldValue);
                            }
                            break;
                    }
                    // special cases...
                    // last_modified is marked as a number field
                    if ( $field_key == 'last_modified' ) {
                        $fieldValue = date("Y-m-d H:i:s", $result[$field_key]);
                    }
                } else {
                    // Set default/blank value
                    switch ($type) {
                        case 'number':
                            $fieldValue = $field['default'] ?? 0;
                            break;
                        case 'key_select':
                            $fieldValue = null;
                            break;
                        case 'multi_select':
                            $fieldValue = $flatten ? null : array();
                            break;
                        case 'array':
                        case 'boolean':
                        case 'date':
                        case 'text':
                        case 'location':
                        default:
                            $fieldValue = $field['default'] ?? null;
                            break;
                    }
                }

                // if we calculated the baptism generation, set it here
                if ( $field_key == 'baptism_generation' && isset($contact_generations[$result['ID']]) ) {
                    $fieldValue = $contact_generations[$result['ID']];
                }

                $fieldValue = apply_filters('dt_data_reporting_field_output', $fieldValue, $type, $field_key, $flatten);
                $contact[$field_key] = $fieldValue;
            }
            $contact['site'] = $base_url;

            $items[] = $contact;
        }
        $columns = array();
        array_push( $columns, array(
            'key' => "id",
            'name' => "ID",
            'type' => 'number',
            'bq_type' => 'INTEGER',
            'bq_mode' => 'NULLABLE',
        ), array(
            'key' => "created",
            'name' => "Created",
            'type' => 'date',
            'bq_type' => 'TIMESTAMP',
            'bq_mode' => 'NULLABLE',
        ));

        foreach ( $fields as $field_key => $field ){
            // skip if field is hidden
            if ( isset( $field['hidden'] ) && $field['hidden'] == true ) {
                continue;
            }
            // skip if in list of excluded fields
            if ( in_array( $field_key, $excluded_fields ) ) {
                continue;
            }

          $column = array(
            'key' => $field_key,
            'name' => $field['name'],
            'type' => $field['type'],
          );
          switch ($field['type']) {
            case 'array':
            case 'location':
            case 'multi_select':
              $column['bq_type'] = 'STRING';
              $column['bq_mode'] = 'REPEATED';
              break;
            case 'connection':
            case 'user_select':
              $column['bq_type'] = 'INTEGER';
              $column['bq_mode'] = 'REPEATED';
              break;
            case 'date':
              $column['bq_type'] = 'TIMESTAMP';
              $column['bq_mode'] = 'NULLABLE';
              break;
            case 'number':
              $column['bq_type'] = 'INTEGER';
              $column['bq_mode'] = 'NULLABLE';
              break;
            case 'boolean':
              $column['bq_type'] = 'BOOLEAN';
              $column['bq_mode'] = 'NULLABLE';
              break;
            case 'key_select':
            case 'text':
            default:
              $column['bq_type'] = 'STRING';
              $column['bq_mode'] = 'NULLABLE';
              break;
          }
          if ( $field_key == 'last_modified' ) {
            $column['type'] = 'date';
            $column['bq_type'] = 'TIMESTAMP';
            $column['bq_mode'] = 'NULLABLE';

          }
          array_push($columns, $column);
        }
        array_push( $columns, array(
            'key' => 'site',
            'name' => 'Site',
            'type' => 'text',
            'bq_type' => 'STRING',
            'bq_mode' => 'NULLABLE',
        ));
        return array( $columns, $items, $contacts['total'] );
    }

    public static function get_contact_activity( $flatten = false, $filter = null ) {
        $filter = $filter ?? array();

        $activities = self::get_post_activity('contacts', $filter);
//        $contacts = DT_Posts::list_posts('contacts', $filter);
        // todo: if total is greater than length, recursively get more
        dt_write_log(sizeof($activities['activity']) . ' of ' . $activities['total']);
        $items = [];

        $base_url = self::get_current_site_base_url();

        foreach ($activities['activity'] as $index => $result) {
            $activity = $result;
            $activity['site'] = $base_url;

            $items[] = $activity;
        }

        $columns = array(
            array(
                'key' => "id",
                'name' => 'ID',
            ),
            array(
                'key' => "post_id",
                'name' => 'Contact ID',
            ),
            array(
                'key' => "user_id",
                'name' => 'User ID',
            ),
            array(
                'key' => "user_name",
                'name' => 'User',
            ),
            array(
                'key' => "action_type",
                'name' => 'Action Type',
            ),
            array(
                'key' => "action_field",
                'name' => 'Action Field',
            ),
            array(
                'key' => "action_value",
                'name' => 'Action Value',
            ),
            array(
                'key' => "action_value_friendly",
                'name' => 'Action Value (Friendly)',
            ),
            array(
                'key' => "action_value_order",
                'name' => 'Action Value Order',
            ),
            array(
                'key' => "action_old_value",
                'name' => 'Action Old Value',
            ),
            array(
                'key' => "note",
                'name' => 'Note',
            ),
            array(
                'key' => "date",
                'name' => 'Date'
            ),
            array(
                'key' => 'site',
                'name' => 'Site'
            ),
        );

        return array( $columns, $items, $activities['total'] );
    }

    private static function get_post_activity( $post_type, $filter ) {
        global $wpdb;

        $post_filter = $filter;
        $post_filter['limit'] = 1000; //todo: this is liable to break. We need a way of getting all contact IDs
        $data = DT_Posts::search_viewable_post( $post_type, $post_filter );
//        dt_write_log( json_encode( $data ) ); // FOR DEBUGGING
        $post_ids = dt_array_to_sql( array_map(function ($post) { return $post->ID; }, $data['posts']) );

        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        $fields = $post_settings["fields"];
        $hidden_fields = ['duplicate_of'];
        foreach ( $fields as $field_key => $field ){
            if ( isset( $field["hidden"] ) && $field["hidden"] === true ){
                $hidden_fields[] = $field_key;
            }
        }
        $hidden_keys = dt_array_to_sql( $hidden_fields );
        // phpcs:disable
        // WordPress.WP.PreparedSQL.NotPrepared
        $query_activity_select = "SELECT
                meta_id,
                object_id,
                user_id,
                user_caps,
                action,
                meta_key,
                meta_value,
                old_value,
                object_subtype,
                field_type,
                object_note,
                FROM_UNIXTIME(hist_time) AS date ";
        $query_activity_from = "FROM `$wpdb->dt_activity_log` ";
        $query_activity_where = "
            WHERE `object_type` = %s
                 AND meta_key NOT IN ( $hidden_keys ) 
                 AND object_id IN ( $post_ids ) ";

        $query_comments_select = "SELECT comment_ID as meta_id,
                comment_post_ID as object_id,
                user_id,
                comment_author as user_caps,
                comment_type as action,
                NULL as meta_key,
                NULL as meta_value,
                NULL as old_value,
                NULL as object_subtype,
                NULL as field_type,
                comment_content as object_note,
                comment_date_gmt as date ";
        $query_comments_from = "FROM wp_comments c
            LEFT JOIN wp_posts p on c.comment_post_ID=p.ID ";
        $query_comments_where = "
            WHERE comment_type not in ('comment', 'duplicate')
                AND p.post_type=%s 
                AND comment_post_ID IN ( $post_ids ) ";

        $query = "$query_activity_select
            $query_activity_from
            $query_activity_where
            UNION
            $query_comments_select
            $query_comments_from
            $query_comments_where
            ORDER BY date ASC ";
        $params = array($post_type, $post_type);

        $total_activities = $wpdb->get_var($wpdb->prepare(
          "SELECT count(*) from ($query) as temp",
          $params
        ));
        if (isset($filter['limit'])) {
            $query .= "LIMIT %d ";
            $params[] = $filter['limit'];
        }
        $activity = $wpdb->get_results( $wpdb->prepare(
            $query,
            $params
        ) );

        //@phpcs:enable
        $activity_simple = [];
        foreach ( $activity as $a ) {
            $a->object_note = DT_Posts::format_activity_message( $a, $post_settings );

            $value_friendly = $a->meta_value;
            $value_order = 0;
            if (isset($fields[$a->meta_key])) {
                switch ($fields[$a->meta_key]["type"]) {
                    case 'key_select':
                    case 'multi_select':
                        $keys = array_keys($fields[$a->meta_key]["default"]);
                        $value_friendly = $fields[$a->meta_key]["default"][$a->meta_value]["label"] ?? $a->meta_value;
                        $value_order = array_search($a->meta_value, $keys) + 1;
                        break;
                    default;
                        break;
                }
            }
            $activity_simple[] = [
                "id" => $a->meta_id,
                "post_id" => $a->object_id,
                "user_id" => $a->user_id,
                "user_name" => $a->user_caps,
                "action_type" => $a->action,
                "action_field" => $a->meta_key,
                "action_value" => $a->meta_value,
                "action_value_friendly" => $value_friendly,
                "action_value_order" => $value_order,
                "action_old_value" => $a->old_value,
                "note" => $a->object_note,
                "date" => $a->date,
            ];
        }

//    $paged = array_slice( $activity_simple, $args["offset"] ?? 0, $args["number"] ?? 1000 );
        //todo: get the real total apart from limit
        return [
            "activity" => $activity_simple,
            "total" => $total_activities
        ];
    }

    private static function getLabel($result, $key) {
        return array_key_exists($key, $result) && array_key_exists('label', $result[$key]) ? $result[$key]['label'] : '';
    }

    protected static function get_current_site_base_url() {
        $url = str_replace( 'http://', '', home_url() );
        $url = str_replace( 'https://', '', $url );

        return trim( $url );
    }

    public static function get_configs() {
      $configurations_str = get_option( "dt_data_reporting_configurations");
      $configurations_int = json_decode( $configurations_str, true );
      $configurations_ext = apply_filters('dt_data_reporting_configurations', array());

      // Merge locally-created and external configurations
      $configurations = array_merge($configurations_int, $configurations_ext);

      // Filter out disabled configurations
      $configurations = array_filter($configurations, function ($config) {
        return isset($config['active']) && $config['active'] == 1;
      });
      return $configurations;
    }
    public static function get_config_by_key( $config_key ) {
      $configurations = self::get_configs();

      if ( isset( $configurations[$config_key] ) ) {
        return $configurations[$config_key];
      }

      return null;
    }
}
