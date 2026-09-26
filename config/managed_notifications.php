<?php

return [
    'queue_connection' => env('NOTIFICATION_QUEUE_CONNECTION', 'redis'),
    'queues' => [
        'resolve' => 'default',
        'dashboard' => 'default',
        'email' => 'emails',
        'webhook' => 'default',
    ],
    'allowed_senders' => array_values(array_filter(array_unique(array_merge(
        [env('MAIL_FROM_ADDRESS')],
        array_map('trim', explode(',', (string) env('NOTIFICATION_SENDER_EMAILS', '')))
    )))),
    // Operators control this allowlist and the network egress policy. No arbitrary destinations.
    'webhook_allowed_hosts' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('NOTIFICATION_WEBHOOK_ALLOWED_HOSTS', ''))
    ))),
    'max_attempts' => 4,
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 180),
    'types' => [
        'meta.template_status_changed' => [
            'name' => 'WhatsApp template status changed',
            'variables' => ['organization_name', 'template_name', 'old_status', 'new_status'],
            'dashboard' => 'Template {{template_name}} changed from {{old_status}} to {{new_status}}.',
            'subject' => 'WhatsApp template status: {{template_name}}',
            'email' => 'In {{organization_name}}, template {{template_name}} changed from {{old_status}} to {{new_status}}.',
        ],
        'meta.business_capability_changed' => [
            'name' => 'WhatsApp messaging limit changed',
            'variables' => ['organization_name', 'old_limit', 'new_limit'],
            'dashboard' => 'WhatsApp messaging limit changed from {{old_limit}} to {{new_limit}}.',
            'subject' => 'WhatsApp messaging limit changed for {{organization_name}}',
            'email' => 'The WhatsApp messaging limit for {{organization_name}} changed from {{old_limit}} to {{new_limit}}.',
        ],
        'meta.phone_status_changed' => [
            'name' => 'WhatsApp phone status changed',
            'variables' => ['organization_name', 'phone_number', 'old_status', 'new_status'],
            'dashboard' => 'WhatsApp phone {{phone_number}} changed from {{old_status}} to {{new_status}}.',
            'subject' => 'WhatsApp phone status changed for {{organization_name}}',
            'email' => 'WhatsApp phone {{phone_number}} in {{organization_name}} changed from {{old_status}} to {{new_status}}.',
        ],
    ],
];
