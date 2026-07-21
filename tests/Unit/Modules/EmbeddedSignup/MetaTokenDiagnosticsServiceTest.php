<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\EmbeddedSignup\Services\MetaTokenDiagnosticsService;

it('accepts a valid token and extracts only the WhatsApp management WABA target', function () {
    Http::fake([
        '*debug_token*' => Http::response([
            'data' => [
                'is_valid' => true,
                'app_id' => 'app-123',
                'type' => 'SYSTEM_USER',
                'expires_at' => 0,
                'scopes' => [
                    'whatsapp_business_management',
                    'whatsapp_business_messaging',
                    'business_management',
                ],
                'granular_scopes' => [
                    [
                        'scope' => 'business_management',
                        'target_ids' => ['business-not-waba'],
                    ],
                    [
                        'scope' => 'whatsapp_business_management',
                        'target_ids' => ['waba-123'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = new MetaTokenDiagnosticsService('v23.0', 'app-123', 'secret-123');
    $inspection = $service->inspectToken('onboarding-token');

    expect($inspection['success'])->toBeTrue()
        ->and($inspection['classification'])->toBe('TOKEN_VALID')
        ->and($inspection['details']['missing_scopes'])->toBe([])
        ->and($service->firstWhatsappManagementTarget($inspection))->toBe('waba-123')
        ->and(json_encode($inspection))->not->toContain('onboarding-token');
});

it('explains that Advanced Access does not add a missing permission to the issued token', function () {
    Http::fake([
        '*debug_token*' => Http::response([
            'data' => [
                'is_valid' => true,
                'app_id' => 'app-123',
                'type' => 'SYSTEM_USER',
                'scopes' => ['whatsapp_business_messaging'],
            ],
        ]),
    ]);

    $service = new MetaTokenDiagnosticsService('v23.0', 'app-123', 'secret-123');
    $inspection = $service->inspectToken('onboarding-token');

    expect($inspection['success'])->toBeFalse()
        ->and($inspection['classification'])->toBe('TOKEN_MISSING_OAUTH_SCOPES')
        ->and($inspection['details']['missing_scopes'])->toBe(['whatsapp_business_management'])
        ->and($service->supportsCentralManagementFallback($inspection))->toBeTrue()
        ->and($inspection['message'])->toContain('Advanced Access')
        ->and(json_encode($inspection))->not->toContain('onboarding-token');
});

it('validates a central fallback token against the management scope only', function () {
    Http::fake([
        '*debug_token*' => Http::response([
            'data' => [
                'is_valid' => true,
                'app_id' => 'app-123',
                'type' => 'SYSTEM_USER',
                'scopes' => ['whatsapp_business_management'],
            ],
        ]),
    ]);

    $service = new MetaTokenDiagnosticsService('v23.0', 'app-123', 'secret-123');
    $inspection = $service->inspectToken(
        'central-system-token',
        ['whatsapp_business_management']
    );

    expect($inspection['success'])->toBeTrue()
        ->and($inspection['classification'])->toBe('TOKEN_VALID')
        ->and($inspection['details']['required_scopes'])->toBe(['whatsapp_business_management'])
        ->and($inspection['details']['missing_scopes'])->toBe([])
        ->and($service->supportsCentralManagementFallback($inspection))->toBeFalse()
        ->and(json_encode($inspection))->not->toContain('central-system-token');
});

it('proves an asset-task problem when only the onboarding token is denied template access', function () {
    Http::fake(function (Request $request) {
        $authorization = $request->header('Authorization');
        $authorization = is_array($authorization)
            ? ($authorization[0] ?? '')
            : (string) $authorization;
        $url = $request->url();

        if (str_contains($url, '/message_templates')) {
            if ($authorization === 'Bearer central-system-token') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'error' => [
                    'message' => '(#200) You do not have permission to access this field.',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'fbtrace_id' => 'trace-123',
                ],
            ], 403);
        }

        return Http::response(['data' => [], 'id' => 'waba-123']);
    });

    $service = new MetaTokenDiagnosticsService(
        'v23.0',
        'app-123',
        'secret-123',
        'central-system-token'
    );
    $diagnosis = $service->diagnoseWabaAccess('onboarding-token', 'waba-123');

    expect($diagnosis['success'])->toBeFalse()
        ->and($diagnosis['classification'])->toBe('WABA_TEMPLATE_ACCESS_DENIED_ONBOARDING_TOKEN_ONLY')
        ->and($diagnosis['details']['checks']['message_templates']['graph_error']['code'])->toBe(200)
        ->and($diagnosis['details']['comparison_system_token']['message_templates']['success'])->toBeTrue()
        ->and($diagnosis['message'])->toContain('asset-task')
        ->and(json_encode($diagnosis))->not->toContain('onboarding-token')
        ->and(json_encode($diagnosis))->not->toContain('central-system-token');
});

it('confirms WABA access only when templates phone assets and subscriptions are readable', function () {
    Http::fake([
        '*' => Http::response(['data' => [], 'id' => 'waba-123']),
    ]);

    $service = new MetaTokenDiagnosticsService('v23.0', 'app-123', 'secret-123');
    $diagnosis = $service->diagnoseWabaAccess('onboarding-token', 'waba-123');

    expect($diagnosis['success'])->toBeTrue()
        ->and($diagnosis['classification'])->toBe('WABA_ACCESS_CONFIRMED')
        ->and($diagnosis['details']['checks']['message_templates']['success'])->toBeTrue()
        ->and($diagnosis['details']['checks']['phone_numbers']['success'])->toBeTrue();
});

it('rejects a management fallback that cannot inspect WABA subscriptions', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/subscribed_apps')) {
            return Http::response([
                'error' => [
                    'message' => '(#200) You do not have permission to access this field.',
                    'type' => 'OAuthException',
                    'code' => 200,
                    'fbtrace_id' => 'trace-subscriptions',
                ],
            ], 403);
        }

        return Http::response(['data' => [], 'id' => 'waba-123']);
    });

    $service = new MetaTokenDiagnosticsService('v23.0', 'app-123', 'secret-123');
    $diagnosis = $service->diagnoseWabaAccess('central-system-token', 'waba-123');

    expect($diagnosis['success'])->toBeFalse()
        ->and($diagnosis['classification'])->toBe('WABA_SUBSCRIPTION_ACCESS_DENIED')
        ->and($diagnosis['details']['checks']['subscribed_apps']['graph_error']['code'])->toBe(200)
        ->and(json_encode($diagnosis))->not->toContain('central-system-token');
});
