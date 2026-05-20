<?php

use App\Models\Contact;
use App\Models\Template;
use App\Services\WhatsappService;

function makeWhatsappServiceForCampaignTemplateTest(): WhatsappService
{
    return new WhatsappService('token', 'v20.0', 'app-id', 'phone-number-id', 'waba-id', 1);
}

it('stores campaign image template media url in chat metadata', function () {
    $service = makeWhatsappServiceForCampaignTemplateTest();

    $metadata = (object) [
        'header' => (object) [
            'format' => 'IMAGE',
            'parameters' => [
                (object) [
                    'type' => 'IMAGE',
                    'selection' => 'static',
                    'value' => 'https://cdn.example.com/template-header.jpg',
                ],
            ],
        ],
        'body' => (object) [
            'text' => 'Hello {{1}}',
            'parameters' => [
                (object) [
                    'selection' => 'static',
                    'value' => 'Sara',
                ],
            ],
        ],
        'buttons' => [
            (object) [
                'type' => 'QUICK_REPLY',
                'text' => 'Reply',
                'value' => null,
            ],
        ],
    ];

    $chatMetadata = json_decode($service->buildCampaignTemplateChatMessage(
        $metadata,
        'contact-uuid',
        new Contact(['first_name' => 'Sara'])
    ), true);

    expect($chatMetadata['type'])->toBe('image')
        ->and($chatMetadata['image']['caption'])->toBe('Hello Sara')
        ->and($chatMetadata['image']['url'])->toBe('https://cdn.example.com/template-header.jpg')
        ->and($chatMetadata['image']['link'])->toBe('https://cdn.example.com/template-header.jpg')
        ->and($chatMetadata['buttons'][0]['text'])->toBe('Reply');
});

it('uses template default header media when campaign metadata has no media url', function () {
    $service = makeWhatsappServiceForCampaignTemplateTest();

    $metadata = (object) [
        'header' => (object) [
            'format' => 'IMAGE',
            'parameters' => [],
        ],
        'body' => (object) [
            'text' => 'Default image template',
            'parameters' => [],
        ],
    ];

    $template = new Template([
        'metadata' => json_encode([
            '_default_header_media' => [
                'url' => 'https://cdn.example.com/default-header.jpg',
                'name' => 'default-header.jpg',
            ],
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'IMAGE',
                ],
            ],
        ]),
    ]);

    $chatMetadata = json_decode($service->buildCampaignTemplateChatMessage(
        $metadata,
        'contact-uuid',
        new Contact(),
        null,
        $template
    ), true);

    expect($chatMetadata['type'])->toBe('image')
        ->and($chatMetadata['image']['caption'])->toBe('Default image template')
        ->and($chatMetadata['image']['url'])->toBe('https://cdn.example.com/default-header.jpg')
        ->and($chatMetadata['image']['filename'])->toBe('default-header.jpg');
});
