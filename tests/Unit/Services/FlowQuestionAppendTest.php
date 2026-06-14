<?php

use Modules\FlowBuilder\Services\FlowExecutionService;

test('it appends a question answer to a formatted cart variable', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(FlowExecutionService::class, 'appendQuestionAnswerToVariable');
    $method->setAccessible(true);

    $variables = $method->invoke($service, [
        'selected_product' => 'برياني غمسة',
        'cart_items' => '• محبوس دجاج × 2',
    ], [
        'append_to_variable' => 'cart_items',
        'append_value_template' => '• {{flow.selected_product}} × {{answer}}',
        'clear_variables_after_append' => ['selected_product'],
    ], '3');

    expect($variables['cart_items'])
        ->toBe("• محبوس دجاج × 2\n• برياني غمسة × 3")
        ->and($variables)->not->toHaveKey('selected_product');
});

test('it leaves variables unchanged when append settings are absent', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(FlowExecutionService::class, 'appendQuestionAnswerToVariable');
    $method->setAccessible(true);

    expect($method->invoke($service, ['quantity' => '1'], [], '1'))
        ->toBe(['quantity' => '1']);
});
