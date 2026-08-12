<?php

use Modules\MetaFlows\Services\MetaFlowService;
use Modules\MetaFlows\Services\MetaFlowResponseNormalizer;

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

test('multi-screen flows carry earlier answers into the final complete payload', function () {
    $service = new MetaFlowService('token', 'v23.0', 'waba', 'phone', 1);
    $json = json_encode([
        'version' => '6.3',
        'screens' => [
            [
                'id' => 'RATING',
                'terminal' => false,
                'layout' => [
                    'type' => 'SingleColumnLayout',
                    'children' => [[
                        'type' => 'Form',
                        'name' => 'rating_form',
                        'children' => [
                            [
                                'type' => 'RadioButtonsGroup',
                                'name' => 'rating',
                                'label' => 'Rating',
                                'data-source' => [['id' => 'five', 'title' => 'Five']],
                            ],
                            [
                                'type' => 'Footer',
                                'on-click-action' => [
                                    'name' => 'navigate',
                                    'next' => ['type' => 'screen', 'name' => 'COMMENTS'],
                                    'payload' => [],
                                ],
                            ],
                        ],
                    ]],
                ],
            ],
            [
                'id' => 'COMMENTS',
                'terminal' => true,
                'layout' => [
                    'type' => 'SingleColumnLayout',
                    'children' => [[
                        'type' => 'Form',
                        'name' => 'comments_form',
                        'children' => [
                            ['type' => 'TextArea', 'name' => 'comments', 'label' => 'Comments'],
                            ['type' => 'Footer', 'on-click-action' => ['name' => 'complete', 'payload' => []]],
                        ],
                    ]],
                ],
            ],
        ],
    ]);

    $normalized = json_decode(invokeMetaFlowServiceMethod($service, 'ensureCompleteActionPayload', [$json]), true);
    $ratingFooter = $normalized['screens'][0]['layout']['children'][0]['children'][1]['on-click-action'];
    $commentsScreen = $normalized['screens'][1];
    $completeFooter = $commentsScreen['layout']['children'][0]['children'][1]['on-click-action'];

    expect($ratingFooter['payload'])->toBe(['rating' => '${form.rating}'])
        ->and($commentsScreen['data']['rating'])->toBe(['type' => 'string', '__example__' => 'example'])
        ->and($completeFooter['payload'])->toBe([
            'comments' => '${form.comments}',
            'rating' => '${data.rating}',
        ]);
});

test('choice option IDs are normalized to their customer-facing titles', function () {
    $flow = new \Modules\MetaFlows\Models\MetaFlow([
        'name' => 'Feedback clients',
        'asset_content' => json_encode([
            'screens' => [[
                'layout' => [
                    'children' => [[
                        'type' => 'Form',
                        'children' => [
                            [
                                'type' => 'RadioButtonsGroup',
                                'name' => 'rating',
                                'data-source' => [
                                    ['id' => 'option_1', 'title' => '⭐⭐⭐⭐⭐ ممتاز'],
                                    ['id' => 'option_2', 'title' => '⭐⭐⭐⭐ جيد جداً'],
                                ],
                            ],
                        ],
                    ]],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $response = new \Modules\MetaFlows\Models\MetaFlowResponse([
        'response_json' => json_encode(['rating' => 'option_1']),
    ]);

    $values = MetaFlowResponseNormalizer::normalize($response, $flow);

    expect($values['rating'])->toBe('⭐⭐⭐⭐⭐ ممتاز');
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
