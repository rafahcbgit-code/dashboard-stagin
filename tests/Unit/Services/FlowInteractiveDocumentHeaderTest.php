<?php

use Modules\FlowBuilder\Services\FlowExecutionService;

function prepareFlowHeader(array $fields): array
{
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(FlowExecutionService::class, 'prepareHeader');
    $method->setAccessible(true);

    return $method->invoke($service, $fields);
}

test('it includes the original filename in an interactive document header', function () {
    $header = prepareFlowHeader([
        'headerType' => 'document',
        'headerMedia' => [
            'path' => 'https://example.com/media/5fd9c8a7',
            'metadata' => json_encode([
                'name' => 'price-list.pdf',
                'extension' => 'application/pdf',
            ]),
        ],
    ]);

    expect($header)->toBe([
        'type' => 'document',
        'document' => [
            'link' => 'https://example.com/media/5fd9c8a7',
            'filename' => 'price-list.pdf',
        ],
    ]);
});

test('it supports array metadata when preparing a document header', function () {
    $header = prepareFlowHeader([
        'headerType' => 'document',
        'headerMedia' => [
            'path' => 'https://example.com/media/document',
            'metadata' => ['name' => 'catalog.pdf'],
        ],
    ]);

    expect($header['document']['filename'])->toBe('catalog.pdf');
});

test('it does not add a filename to non-document media headers', function () {
    $header = prepareFlowHeader([
        'headerType' => 'image',
        'headerMedia' => [
            'path' => 'https://example.com/media/image',
            'metadata' => json_encode(['name' => 'header.jpg']),
        ],
    ]);

    expect($header)->toBe([
        'type' => 'image',
        'image' => ['link' => 'https://example.com/media/image'],
    ]);
});
