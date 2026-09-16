<?php

declare(strict_types=1);

use App\Services\ChatbotStudio\StudioAiService;
use App\Services\ChatbotStudio\SourceReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--model=')) {
        $candidate = substr($argument, strlen('--model='));
        if (!in_array($candidate, config('chatbot_studio.allowed_models', []), true)) {
            fwrite(STDERR, "Diagnostic model must be on the configured allowlist.\n");
            exit(1);
        }
        config(['chatbot_studio.model' => $candidate]);
    }
}

$service = app(StudioAiService::class);
$report = ['configuration' => $service->configuration()];
$exitCode = 0;
$key = (string) config('chatbot_studio.api_key', '');

// Only sanitized provider diagnostics are printed, never headers or exception dumps.
$summarize = static function ($response) use ($key): array {
    $result = ['http_status' => $response->status()];
    $status = $response->json('error.status');
    if (is_string($status) && preg_match('/^[A-Z_]{1,80}$/', $status)) {
        $result['error_status'] = $status;
    }
    $message = $response->json('error.message');
    if (is_string($message)) {
        $message = $key !== '' ? str_replace($key, '[REDACTED]', $message) : $message;
        $message = preg_replace('~https?://[^\s]+|[A-Za-z0-9_.+-]+@[A-Za-z0-9.-]+|[A-Za-z0-9_.-]{24,}~', '[REDACTED]', $message);
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message);
        $result['error_message'] = mb_substr($message, 0, 500);
    }
    return $result;
};

try {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--source=')) {
            $source = app(SourceReader::class)->fromUrl(substr($argument, strlen('--source=')));
            $report['source'] = ['readable' => true, 'bytes' => strlen($source),
                'generation_mode' => strlen($source) > 60000 ? 'staged' : 'direct'];
        }
    }
    $service->assertConfigured();
    $model = config('chatbot_studio.model');
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model;
    $request = static fn () => Http::acceptJson()->asJson()
        ->withHeaders(['x-goog-api-key' => $key])
        ->withOptions(['allow_redirects' => false])->connectTimeout(10)->timeout(60);

    $response = $request()->get($endpoint);
    $report['model_lookup'] = $summarize($response);
    if ($response->successful()) {
        $methods = $response->json('supportedGenerationMethods', []);
        $report['model_lookup']['supports_generate_content'] = is_array($methods) && in_array('generateContent', $methods, true);
    } else {
        $exitCode = 1;
    }

    if (in_array('--probe', $argv, true)) {
        // This opt-in probe sends synthetic text only and does not read or publish drafts.
        $response = $request()->post($endpoint.':generateContent', [
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Return the JSON object {"ready":true}.']]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 1024],
        ]);
        $report['generation_probe'] = $summarize($response);
        $report['generation_probe']['completed'] = $response->successful() && $response->json('candidates.0.finishReason') === 'STOP';
        if (!$report['generation_probe']['completed']) {
            $exitCode = 1;
        }
    } else {
        $report['generation_probe'] = 'Not requested. Pass --probe for one synthetic generation request.';
    }
} catch (ValidationException $e) {
    $report['validation_error'] = 'Check the source URL, source size, API configuration and model allowlist.';
    $exitCode = 1;
} catch (ConnectionException $e) {
    $report['connection_error'] = $service->connectionFailureDetails($e);
    $exitCode = 1;
} catch (Throwable $e) {
    $report['diagnostic_error'] = 'The check could not finish. Check server DNS, TLS certificates and outbound HTTPS access; no exception details were printed.';
    $exitCode = 1;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($exitCode);
