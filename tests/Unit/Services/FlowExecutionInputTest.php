<?php

use Modules\FlowBuilder\Services\FlowExecutionService;

function flowExecutionInputMethod(string $methodName): ReflectionMethod
{
    $method = new ReflectionMethod(FlowExecutionService::class, $methodName);
    $method->setAccessible(true);

    return $method;
}

test('it ignores an audio message without a transcript while a flow is active', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('shouldIgnoreUnprocessableInput');
    $chat = (object) ['metadata' => json_encode(['type' => 'audio'])];

    expect($method->invoke($service, $chat, ''))->toBeTrue();
});

test('it does not ignore a flow message that contains usable text', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('shouldIgnoreUnprocessableInput');
    $chat = (object) ['metadata' => json_encode(['type' => 'audio'])];

    expect($method->invoke($service, $chat, 'transcribed answer'))->toBeFalse();
});

test('it extracts a list reply id title and message context from chat metadata', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('extractInteractiveReply');
    $chat = (object) [
        'metadata' => json_encode([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list_reply',
                'list_reply' => [
                    'id' => '1',
                    'title' => 'خدمة في الصالون',
                ],
            ],
            'context' => ['id' => 'wamid.MENU'],
        ]),
    ];

    expect($method->invoke($service, $chat))->toBe([
        'type' => 'list_reply',
        'id' => '1',
        'title' => 'خدمة في الصالون',
        'context_wam_id' => 'wamid.MENU',
    ]);
});

test('it does not classify free text as an interactive reply', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('extractInteractiveReply');
    $chat = (object) [
        'metadata' => json_encode([
            'type' => 'text',
            'text' => ['body' => 'بكم اسعار تركيب الرموش'],
        ]),
    ];

    expect($method->invoke($service, $chat))->toBeNull();
});

test('only list and reply button nodes wait for inbound interaction', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('expectedInteractiveReplyType');

    expect($method->invoke($service, ['type' => 'interactive list']))->toBe('list_reply')
        ->and($method->invoke($service, [
            'type' => 'interactive buttons',
            'buttonType' => 'buttons',
        ]))->toBe('button_reply')
        ->and($method->invoke($service, [
            'type' => 'interactive buttons',
            'buttonType' => 'ctaUrl',
        ]))->toBeNull()
        ->and($method->invoke($service, [
            'type' => 'interactive call to action url',
        ]))->toBeNull()
        ->and($method->invoke($service, [
            'type' => 'interactive buttons',
        ]))->toBeNull();
});

test('it preserves legacy button webhook payloads as button replies', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = flowExecutionInputMethod('extractInteractiveReply');
    $chat = (object) [
        'metadata' => json_encode([
            'type' => 'button',
            'button' => [
                'payload' => 'button1',
                'text' => 'نعم',
            ],
            'context' => ['id' => 'wamid.BUTTONS'],
        ]),
    ];

    expect($method->invoke($service, $chat))->toBe([
        'type' => 'button_reply',
        'id' => 'button1',
        'title' => 'نعم',
        'context_wam_id' => 'wamid.BUTTONS',
    ]);
});
