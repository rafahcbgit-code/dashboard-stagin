<?php

use App\Models\Template;
use App\Services\Salla\Webhooks\TemplateParameterBuilder;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;

it('uses a tracking link as the dynamic portion of a URL button', function () {
    $template = new Template();
    $template->metadata = json_encode([
        'components' => [[
            'type' => 'BUTTONS',
            'buttons' => [[
                'type' => 'URL',
                'text' => 'Track order',
                'url' => 'https://trackings.sky-sa.net/tracking?tracking_number={{1}}',
            ]],
        ]],
    ]);

    $mappings = [(object) [
        'template_variable' => 'button.url.0.1',
        'payload_path' => 'data.order.shipping.shipment.tracking_link',
        'transform' => null,
        'default_value' => null,
    ]];

    $payload = [
        'data' => [
            'order' => [
                'shipping' => [
                    'shipment' => [
                        'tracking_link' => 'https://trackings.sky-sa.net/tracking?tracking_number=77792944832530',
                    ],
                ],
            ],
        ],
    ];

    [, , $buttons] = (new TemplateParameterBuilder(new WhatsAppTemplateVariableExtractor()))
        ->build($template, $mappings, $payload);

    expect($buttons)->toBe([
        0 => [
            'type' => 'URL',
            'text' => 'Track order',
            'parameters' => [[
                'type' => 'static',
                'value' => '77792944832530',
            ]],
        ],
    ]);
});
