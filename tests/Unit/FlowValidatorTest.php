<?php

use Modules\FlowBuilder\Validators\FlowValidator;

function ctaFlowMetadata(string $displayText): array
{
    return [
        'nodes' => [[
            'id' => '3',
            'type' => 'buttons',
            'data' => [
                'metadata' => [
                    'fields' => [
                        'body' => 'Choose an option',
                        'buttonType' => 'cta_url',
                        'ctaUrlButton' => [
                            'displayText' => $displayText,
                            'url' => 'https://example.com/branches',
                        ],
                    ],
                ],
            ],
        ]],
    ];
}

it('accepts CTA display text containing exactly 20 characters', function () {
    $metadata = ctaFlowMetadata(str_repeat('a', 20));

    expect(app(FlowValidator::class)->validateCtaDisplayTextLengths($metadata))->toBeTrue()
        ->and(app(FlowValidator::class)->validateMessageNodes($metadata))->toBeTrue();
});

it('rejects CTA display text containing more than 20 characters', function () {
    $metadata = ctaFlowMetadata(str_repeat('a', 21));
    $expected = ['buttons' => ['CTA display text must not exceed 20 characters.']];

    expect(app(FlowValidator::class)->validateCtaDisplayTextLengths($metadata))->toBe($expected)
        ->and(app(FlowValidator::class)->validateMessageNodes($metadata))->toBe($expected);
});

it('counts multibyte CTA display text as characters', function () {
    $metadata = ctaFlowMetadata(str_repeat('ع', 20));

    expect(app(FlowValidator::class)->validateCtaDisplayTextLengths($metadata))->toBeTrue();
});
