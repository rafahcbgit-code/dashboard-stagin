<?php

use Modules\MetaFlows\Services\MetaFlowService;

function invokeMetaFlowServiceMethod(MetaFlowService $service, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($service, $method);

    return $reflection->invokeArgs($service, $arguments);
}

test('blank identifiers generated from non latin labels are repaired without changing existing identifiers', function () {
    $service = new MetaFlowService('token', 'v23.0', 'waba', 'phone', 1);
    $json = json_encode([
        'version' => '6.3',
        'screens' => [[
            'id' => 'WELCOME',
            'terminal' => true,
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [[
                    'type' => 'Form',
                    'name' => 'welcome_form',
                    'children' => [
                        [
                            'type' => 'RadioButtonsGroup',
                            'label' => 'كيف تقيّم تجربتك معنا؟',
                            'name' => '',
                            'data-source' => [
                                ['id' => '', 'title' => 'ممتازة'],
                                ['id' => 'good', 'title' => 'جيدة'],
                                ['id' => '', 'title' => 'تحتاج إلى تحسين'],
                                ['id' => 'option_1', 'title' => 'معرّف موجود'],
                            ],
                        ],
                        [
                            'type' => 'TextArea',
                            'label' => 'ملاحظات',
                            'name' => '',
                        ],
                        [
                            'type' => 'Footer',
                            'label' => 'إرسال',
                            'on-click-action' => ['name' => 'complete', 'payload' => []],
                        ],
                    ],
                ]],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $normalized = json_decode(invokeMetaFlowServiceMethod($service, 'ensureCompleteActionPayload', [$json]), true);
    $children = $normalized['screens'][0]['layout']['children'][0]['children'];

    expect($children[0]['name'])->toBe('field_1')
        ->and($children[0]['data-source'][0]['id'])->toBe('option_1_2')
        ->and($children[0]['data-source'][1]['id'])->toBe('good')
        ->and($children[0]['data-source'][2]['id'])->toBe('option_3')
        ->and($children[0]['data-source'][3]['id'])->toBe('option_1')
        ->and($children[1]['name'])->toBe('field_2')
        ->and($children[2]['on-click-action']['payload'])->toBe([
            'field_1' => '${form.field_1}',
            'field_2' => '${form.field_2}',
        ]);
});

test('asset validation errors are detected even when the HTTP request succeeded', function () {
    $service = new MetaFlowService('token', 'v23.0', 'waba', 'phone', 1);
    $response = (object) [
        'success' => true,
        'data' => (object) [
            'success' => true,
            'validation_errors' => [
                (object) ['message' => "Property 'name' should not be blank or an empty string."],
            ],
        ],
    ];

    $errors = invokeMetaFlowServiceMethod($service, 'extractAssetValidationErrors', [$response]);
    $message = invokeMetaFlowServiceMethod($service, 'formatAssetValidationErrors', [$errors]);

    expect($errors)->toHaveCount(1)
        ->and($message)->toContain("Property 'name' should not be blank");
});
