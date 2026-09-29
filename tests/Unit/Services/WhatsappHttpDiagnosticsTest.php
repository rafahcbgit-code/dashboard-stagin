<?php

use App\Services\WhatsappService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\FlowBuilder\Services\FlowExecutionService;

afterEach(function () {
    WhatsappService::resetSharedClient();
});

function diagnosticWhatsappService(array $queue): WhatsappService
{
    $handler = HandlerStack::create(new MockHandler($queue));
    WhatsappService::setSharedClientForTesting(new Client(['handler' => $handler]));

    $reflection = new ReflectionClass(WhatsappService::class);
    $service = $reflection->newInstanceWithoutConstructor();

    foreach ([
        'organizationId' => 397,
        'phoneNumberId' => 'phone-number-id',
    ] as $property => $value) {
        $refProperty = $reflection->getProperty($property);
        $refProperty->setAccessible(true);
        $refProperty->setValue($service, $value);
    }

    return $service;
}

function invokeDiagnosticHttpRequest(WhatsappService $service): object
{
    $method = new ReflectionMethod(WhatsappService::class, 'sendHttpRequest');
    $method->setAccessible(true);

    return $method->invoke(
        $service,
        'POST',
        'https://graph.facebook.com/v20.0/phone-number-id/messages',
        ['type' => 'text'],
        ['Authorization' => 'Bearer hidden']
    );
}

it('captures a non-json Meta gateway response without throwing', function () {
    $service = diagnosticWhatsappService([
        new Response(502, ['x-fb-request-id' => 'request-502'], '<html>Bad Gateway</html>'),
    ]);

    $result = invokeDiagnosticHttpRequest($service);

    expect($result->success)->toBeFalse()
        ->and($result->http_status)->toBe(502)
        ->and($result->meta_request_id)->toBe('request-502')
        ->and($result->failure_kind)->toBe('http_error')
        ->and($result->response_excerpt)->toContain('Bad Gateway')
        ->and($result->correlation_id)->not->toBeEmpty();
});

it('captures an empty error response without dereferencing null', function () {
    $service = diagnosticWhatsappService([
        new Response(500, [], ''),
    ]);

    $result = invokeDiagnosticHttpRequest($service);

    expect($result->success)->toBeFalse()
        ->and($result->http_status)->toBe(500)
        ->and($result->message)->not->toBeEmpty();
});

it('rejects a valid json response with an unexpected shape', function () {
    $service = diagnosticWhatsappService([
        new Response(200, [], '[]'),
    ]);

    $result = invokeDiagnosticHttpRequest($service);

    expect($result->success)->toBeFalse()
        ->and($result->failure_kind)->toBe('invalid_response')
        ->and($result->json_error)->toBe('Unexpected JSON shape');
});

it('captures a connection failure without an HTTP response', function () {
    $request = new Request('POST', 'https://graph.facebook.com/messages');
    $service = diagnosticWhatsappService([
        new ConnectException('Connection timed out', $request, null, ['errno' => 28, 'error' => 'timeout']),
    ]);

    $result = invokeDiagnosticHttpRequest($service);

    expect($result->success)->toBeFalse()
        ->and($result->http_status)->toBeNull()
        ->and($result->failure_kind)->toBe('connection_error')
        ->and($result->curl_errno)->toBe(28)
        ->and($result->curl_error)->toBe('timeout')
        ->and($result->message)->toContain('Connection timed out');
});

it('preserves a successful Meta response and diagnostics', function () {
    $service = diagnosticWhatsappService([
        new Response(200, [
            'x-fb-request-id' => 'request-ok',
            'x-fb-trace-id' => 'trace-ok',
        ], json_encode([
            'messages' => [['id' => 'wamid.TEST']],
        ])),
    ]);

    $result = invokeDiagnosticHttpRequest($service);

    expect($result->success)->toBeTrue()
        ->and($result->http_status)->toBe(200)
        ->and($result->meta_request_id)->toBe('request-ok')
        ->and($result->meta_trace_id)->toBe('trace-ok')
        ->and($result->data->messages[0]->id)->toBe('wamid.TEST');
});

it('does not treat a failed response object as a successful flow send', function () {
    $service = (new ReflectionClass(FlowExecutionService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(FlowExecutionService::class, 'isSuccessfulSendResponse');
    $method->setAccessible(true);

    expect($method->invoke($service, (object) ['success' => false]))->toBeFalse()
        ->and($method->invoke($service, (object) ['success' => true]))->toBeTrue();
});
