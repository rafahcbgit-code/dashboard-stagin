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
