<?php

use App\Services\ChatService;

it('applies static and dynamic template variable overrides without replacing header media', function () {
    $service = (new ReflectionClass(ChatService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ChatService::class, 'applyTemplateVariableOverrides');
    $method->setAccessible(true);

    $metadata = [
        'header' => [
            'format' => 'IMAGE',
            'parameters' => [[
                'type' => 'IMAGE',
                'selection' => 'default',
                'value' => 'https://cdn.example.com/header.jpg',
            ]],
        ],
        'body' => [
            'text' => 'Hello {{1}}',
            'parameters' => [[
                'type' => 'text',
                'selection' => 'static',
                'value' => 'Default name',
            ]],
        ],
        'buttons' => [[
            'type' => 'URL',
            'text' => 'View order',
            'parameters' => [[
                'type' => 'static',
                'value' => 'default-order',
            ]],
        ]],
    ];

    $result = $method->invoke($service, $metadata, [
        'body' => [[
            'selection' => 'dynamic',
            'value' => 'first name',
        ]],
        'header' => [[
            'selection' => 'static',
            'value' => 'must-not-replace-media',
        ]],
        'buttons' => [
            0 => [[
                'selection' => 'static',
                'value' => 'order-123',
            ], [
                'selection' => 'static',
                'value' => 'extra-parameter',
            ]],
        ],
    ]);

    expect($result['header']['parameters'][0]['type'])->toBe('IMAGE')
        ->and($result['header']['parameters'][0]['value'])->toBe('https://cdn.example.com/header.jpg')
        ->and($result['body']['parameters'][0])->toMatchArray([
            'type' => 'text',
            'selection' => 'dynamic',
            'value' => 'first name',
        ])
        ->and($result['buttons'][0]['parameters'][0])->toBe([
            'type' => 'static',
            'value' => 'order-123',
        ])
        ->and($result['buttons'][0]['parameters'])->toHaveCount(1);
});

it('applies text header overrides and leaves metadata unchanged without overrides', function () {
    $service = (new ReflectionClass(ChatService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ChatService::class, 'applyTemplateVariableOverrides');
    $method->setAccessible(true);

    $metadata = [
        'header' => [
            'format' => 'TEXT',
            'parameters' => [[
                'type' => 'text',
                'selection' => 'static',
                'value' => 'Default header',
            ]],
        ],
        'body' => ['text' => 'No variables', 'parameters' => []],
        'buttons' => [],
    ];

    expect($method->invoke($service, $metadata, []))->toBe($metadata);

    $result = $method->invoke($service, $metadata, [
        'header' => [[
            'selection' => 'dynamic',
            'value' => 'name',
        ]],
    ]);

    expect($result['header']['parameters'][0])->toMatchArray([
        'type' => 'text',
        'selection' => 'dynamic',
        'value' => 'name',
    ]);
});
