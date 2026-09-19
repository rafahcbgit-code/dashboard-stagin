<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Event::listen(Illuminate\Http\Client\Events\ResponseReceived::class, function ($event) {
    $input = json_decode($event->request['contents'][0]['parts'][0]['text'], true);
    $schema = $event->request['generationConfig']['responseJsonSchema'] ?? [];
    $stage = isset($input['requested_keys']) ? 'batch' : (isset($schema['properties']['screens']['items']['properties']['source_hint']) ? 'plan' : 'direct');
    echo json_encode(['http_status' => $event->response->status(), 'stage' => $stage]).PHP_EOL;
    if (!$event->response->successful()) {
        $message = (string) $event->response->json('error.message', 'Provider did not return a JSON error.');
        $key = (string) config('chatbot_studio.api_key');
        $message = $key !== '' ? str_replace($key, '[REDACTED]', $message) : $message;
        echo json_encode(['http_status' => $event->response->status(), 'provider_error' => mb_substr($message, 0, 1000)], JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    }
});

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--model=')) {
        $model = substr($argument, 8);
        if (!in_array($model, config('chatbot_studio.allowed_models', []), true)) {
            fwrite(STDERR, "Model is not allowlisted.\n");
            exit(1);
        }
        config(['chatbot_studio.model' => $model]);
    }
}

// Synthetic fixtures only. This script never reads customer sources or writes flows/drafts.
$root = ['key' => 'welcome', 'name' => 'Welcome', 'messages' => ["Welcome to our test service.\nChoose a department."], 'questions' => [], 'choices' => [], 'list_label' => 'Departments', 'next' => null, 'cta' => null];
$screens = [];
foreach (['Hours', 'Location', 'Support', 'Prices'] as $index => $label) {
    $key = 'department_'.$index;
    $root['choices'][] = ['label' => $label, 'target' => $key];
    $screens[] = ['key' => $key, 'name' => $label, 'messages' => ["Exact answer for $label.\nKeep this second line."], 'questions' => [],
        'choices' => [['label' => 'Main menu', 'target' => 'welcome']], 'list_label' => null, 'next' => null, 'cta' => null];
}
$source = ['name' => 'Synthetic verification', 'start' => 'welcome', 'trigger' => '000', 'issues' => [], 'screens' => [$root, ...$screens]];
$instruction = 'Use this complete source exactly, including keys, messages, line breaks, labels, and routing. No external actions are required.';

try {
    if (in_array('--direct', $argv, true)) {
        $blueprint = app(App\Services\ChatbotStudio\BlueprintGenerator::class)->generate(json_encode($source), $instruction);
    } else {
        $recovery = app(App\Services\ChatbotStudio\BlueprintRecovery::class);
        $plan = $recovery->plan(json_encode($source), $instruction, null);
        $completed = [];
        foreach (array_chunk(array_column($plan['screens'], 'key'), 3) as $keys) {
            $batch = $recovery->batch(json_encode($source), $instruction, null, $plan, $completed, $keys, 24000);
            $completed = array_merge($completed, $batch['screens']);
            $plan['issues'] = array_values(array_unique(array_merge($plan['issues'], $batch['issues'])));
        }
        $blueprint = $recovery->assemble($plan, $completed, true);
    }
    $compiled = app(App\Services\ChatbotStudio\BlueprintCompiler::class)->compile($blueprint);
    $expected = array_column($source['screens'], null, 'key');
    foreach ($blueprint['screens'] as $screen) {
        if (!isset($expected[$screen['key']]) || $screen['messages'] !== $expected[$screen['key']]['messages'] || $screen['choices'] != $expected[$screen['key']]['choices']) {
            throw new RuntimeException('Synthetic source fidelity check failed.');
        }
    }
    if (count($blueprint['screens']) !== 5 || $blueprint['issues']) {
        throw new RuntimeException('Synthetic completeness check failed.');
    }
    echo json_encode(['success' => true, 'model' => config('chatbot_studio.model'), 'screens' => count($blueprint['screens']),
        'compiled_flows' => count($compiled['flows']), 'compiled_triggers' => count($compiled['triggers']),
        'exact_text_and_routing' => true, 'database_writes' => false], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    $errors = $e instanceof Illuminate\Validation\ValidationException ? $e->errors() : ['verification' => ['Synthetic verification failed. No database changes were made.']];
    echo json_encode(['success' => false, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit(1);
}
