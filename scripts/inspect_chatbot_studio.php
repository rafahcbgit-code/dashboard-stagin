<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

echo json_encode([
    'queue' => config('queue.default'),
    'ai' => app(App\Services\ChatbotStudio\StudioAiService::class)->configuration(),
    'jobs_table' => Illuminate\Support\Facades\Schema::hasTable('jobs'),
    'drafts_table' => Illuminate\Support\Facades\Schema::hasTable('chatbot_studio_drafts'),
], JSON_PRETTY_PRINT) . PHP_EOL;
try {
    $source = app(App\Services\ChatbotStudio\SourceReader::class)->fromUrl('https://chatbot.chatberry.click/hpfO3JM5');
    echo json_encode(['simulator_bytes' => strlen($source), 'contains_script' => str_contains($source, '<script'), 'contains_rento' => stripos($source, 'rento') !== false]) . PHP_EOL;
} catch (Throwable $e) {
    echo 'Simulator read failed: ' . $e->getMessage() . PHP_EOL;
}
try {
    $answer = app(App\Services\ChatbotStudio\StudioAiService::class)->chat('Respond with JSON only.', 'Return {"ready":true}.', ['max_tokens' => 100, 'timeout' => 30]);
    echo json_encode(['ai_success' => true, 'answer' => $answer], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    echo 'AI check failed: ' . $e->getMessage() . PHP_EOL;
}
