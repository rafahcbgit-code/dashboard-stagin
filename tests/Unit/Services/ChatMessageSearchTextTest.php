<?php

use App\Services\ChatMessageSearchService;

uses(Tests\TestCase::class);

it('extracts the text shown in a message including a generic media caption', function () {
    $metadata = json_encode([
        'type' => 'image',
        'media' => ['caption' => 'صورة موعد الاجتماع'],
    ], JSON_UNESCAPED_UNICODE);

    expect(ChatMessageSearchService::visibleText($metadata))->toBe('صورة موعد الاجتماع');
});

it('does not index a locally marked revoked message', function () {
    $metadata = json_encode([
        'type' => 'text',
        'text' => ['body' => 'هلوو'],
        'revoked' => ['is_revoked' => true],
    ], JSON_UNESCAPED_UNICODE);

    expect(ChatMessageSearchService::visibleText($metadata))->toBe('');
});

it('normalizes Arabic spelling for matching', function () {
    expect(ChatMessageSearchService::normalize('إلـى السَّلَام'))->toBe('الي السلام');
});
