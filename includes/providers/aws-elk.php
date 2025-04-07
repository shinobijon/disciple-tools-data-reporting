<?php
add_filter("dt_data_reporting_providers", "dt_elk_add_provider", 10, 4);
function dt_elk_add_provider($providers) {
    $providers['aws-elk'] = [
        'name' => 'AWS ELK Webhook',
        'flatten' => true,
        'fields' => [
            'webhook_url' => [
                'label' => 'Webhook URL',
                'type' => 'text',
                'helpText' => 'The HTTP endpoint to receive data for Logstash'
            ]
        ]
    ];
    return $providers;
}

add_action("dt_data_reporting_run_export", "dt_elk_run_export", 10, 3);
function dt_elk_run_export($export_id, $export_config, $data) {
    if ($export_config['provider'] !== 'aws-elk') return;

    $webhook_url = $export_config['fields']['webhook_url'] ?? '';
    if (!$webhook_url) return;

    $response = wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($data),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        error_log("ELK Export Error: " . $response->get_error_message());
    } else {
        error_log("ELK Export Successful");
    }
}
