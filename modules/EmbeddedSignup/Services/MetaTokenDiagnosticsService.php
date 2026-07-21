<?php

namespace Modules\EmbeddedSignup\Services;

use Illuminate\Support\Facades\Http;

class MetaTokenDiagnosticsService
{
    private const REQUIRED_SCOPES = [
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ];

    public function __construct(
        private readonly string $apiVersion,
        private readonly ?string $appId,
        private readonly ?string $appSecret,
        private readonly ?string $comparisonSystemToken = null,
    ) {
    }

    /**
     * Inspect the token returned by Facebook Login for Business without exposing it.
     */
    public function inspectToken(string $token, ?array $requiredScopes = null): array
    {
        $requiredScopes = $requiredScopes ?? self::REQUIRED_SCOPES;

        if (empty($this->appId) || empty($this->appSecret)) {
            return $this->failure(
                'APP_CREDENTIALS_MISSING',
                'The Meta App ID or App Secret is missing, so the onboarding token cannot be inspected.',
                ['token_fingerprint' => $this->fingerprint($token)]
            );
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withToken($this->appId.'|'.$this->appSecret)
                ->get("https://graph.facebook.com/{$this->apiVersion}/debug_token", [
                    'input_token' => $token,
                ]);
        } catch (\Throwable $exception) {
            return $this->failure(
                'TOKEN_DEBUG_REQUEST_FAILED',
                'Meta could not be reached while inspecting the onboarding token.',
                [
                    'token_fingerprint' => $this->fingerprint($token),
                    'exception' => $exception::class,
                ]
            );
        }

        $payload = $response->json();
        $graphError = $this->extractGraphError($payload, $response->status());

        if (! $response->successful() || isset($payload['error']) || isset($payload['data']['error'])) {
            return $this->failure(
                'TOKEN_DEBUG_REJECTED',
                'Meta rejected the onboarding token inspection request.',
                [
                    'token_fingerprint' => $this->fingerprint($token),
                    'graph_error' => $graphError,
                ]
            );
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $scopes = $this->normaliseScopes($data);
        $missingScopes = array_values(array_diff($requiredScopes, $scopes));
        $isValid = (bool) ($data['is_valid'] ?? false);
        $actualAppId = isset($data['app_id']) ? (string) $data['app_id'] : null;
        $appMatches = $actualAppId !== null && hash_equals((string) $this->appId, $actualAppId);

        $details = [
            'token_fingerprint' => $this->fingerprint($token),
            'is_valid' => $isValid,
            'type' => $data['type'] ?? null,
            'app_id' => $actualAppId,
            'expected_app_id' => (string) $this->appId,
            'app_matches' => $appMatches,
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'data_access_expires_at' => $data['data_access_expires_at']
                ?? $data['data_access_expiration_time']
                ?? null,
            'scopes' => $scopes,
            'required_scopes' => $requiredScopes,
            'missing_scopes' => $missingScopes,
            'granular_scopes' => $this->sanitiseGranularScopes($data['granular_scopes'] ?? []),
        ];

        if (! $isValid) {
            return $this->failure(
                'TOKEN_INVALID',
                'Meta returned an invalid onboarding token. Repeat Embedded Signup and exchange the code immediately.',
                $details
            );
        }

        if (! $appMatches) {
            return $this->failure(
                'TOKEN_APP_MISMATCH',
                'The onboarding token belongs to a different Meta app. Verify that App ID, App Secret and Configuration ID belong to the same app.',
                $details
            );
        }

        if ($missingScopes !== []) {
            return $this->failure(
                'TOKEN_MISSING_OAUTH_SCOPES',
                'The app may have Advanced Access, but the issued token itself is missing: '.implode(', ', $missingScopes).'. Verify the permissions selected inside the Facebook Login for Business configuration and reconnect the business.',
                $details
            );
        }

        return [
            'success' => true,
            'classification' => 'TOKEN_VALID',
            'message' => 'The onboarding token is valid and contains the required WhatsApp OAuth scopes.',
            'details' => $details,
        ];
    }

    /**
     * Return the first WABA target granted specifically to whatsapp_business_management.
     */
    public function firstWhatsappManagementTarget(array $inspection): ?string
    {
        foreach ($inspection['details']['granular_scopes'] ?? [] as $scope) {
            if (($scope['scope'] ?? null) !== 'whatsapp_business_management') {
                continue;
            }

            $targetId = $scope['target_ids'][0] ?? null;

            return $targetId !== null ? (string) $targetId : null;
        }

        return null;
    }

    /**
     * A messaging-only onboarding token can be paired with a separately validated
     * central management token. No other missing-scope combination is safe.
     */
    public function supportsCentralManagementFallback(array $inspection): bool
    {
        if (($inspection['classification'] ?? null) !== 'TOKEN_MISSING_OAUTH_SCOPES') {
            return false;
        }

        $scopes = $inspection['details']['scopes'] ?? [];
        $missingScopes = $inspection['details']['missing_scopes'] ?? [];

        sort($missingScopes);

        return in_array('whatsapp_business_messaging', $scopes, true)
            && $missingScopes === ['whatsapp_business_management'];
    }

    /**
     * Prove whether the issued token has asset-level access to the selected WABA.
     */
    public function diagnoseWabaAccess(string $token, string $wabaId): array
    {
        $checks = [
            'waba' => $this->probe($token, $wabaId, [
                'fields' => 'id,name',
            ]),
            'phone_numbers' => $this->probe($token, $wabaId.'/phone_numbers', [
                'limit' => 1,
                'fields' => 'id,display_phone_number,status',
            ]),
            'message_templates' => $this->probe($token, $wabaId.'/message_templates', [
                'limit' => 1,
                'fields' => 'id,name,status',
            ]),
            'subscribed_apps' => $this->probe($token, $wabaId.'/subscribed_apps', [
                'limit' => 1,
            ]),
        ];

        $details = [
            'token_fingerprint' => $this->fingerprint($token),
            'waba_id' => $wabaId,
            'checks' => $checks,
        ];

        if (! $checks['waba']['success']) {
            return $this->assetAccessFailure(
                'WABA_ACCESS_DENIED',
                'The token is valid but cannot read the selected WABA. The WABA was not granted to the system user produced by this login configuration, or the session returned a different WABA ID.',
                $token,
                $wabaId,
                $details
            );
        }

        if (! $checks['message_templates']['success']) {
            return $this->assetAccessFailure(
                'WABA_TEMPLATE_ACCESS_DENIED',
                'The token has the required OAuth scopes but Meta denied the message_templates edge. This points to missing WABA asset-task access for the issued system user (template view/manage), not missing Advanced Access on the app.',
                $token,
                $wabaId,
                $details
            );
        }

        if (! $checks['phone_numbers']['success']) {
            return $this->failure(
                'WABA_PHONE_ACCESS_DENIED',
                'Template access works, but the issued token cannot read phone numbers for the selected WABA. Verify the phone asset tasks in the login configuration.',
                $details
            );
        }

        if (! $checks['subscribed_apps']['success']) {
            return $this->failure(
                'WABA_SUBSCRIPTION_ACCESS_DENIED',
                'The token can read the WABA, phone numbers and templates, but it cannot access subscribed_apps. Full onboarding cannot continue because the app webhook subscription could not be verified.',
                $details
            );
        }

        return [
            'success' => true,
            'classification' => 'WABA_ACCESS_CONFIRMED',
            'message' => 'The issued token can access the selected WABA, its phone numbers, message templates and app subscriptions.',
            'details' => $details,
        ];
    }

    private function assetAccessFailure(
        string $classification,
        string $message,
        string $onboardingToken,
        string $wabaId,
        array $details
    ): array {
        if (! empty($this->comparisonSystemToken)
            && ! hash_equals($onboardingToken, (string) $this->comparisonSystemToken)) {
            $comparison = $this->probe(
                (string) $this->comparisonSystemToken,
                $wabaId.'/message_templates',
                ['limit' => 1, 'fields' => 'id']
            );

            $details['comparison_system_token'] = [
                'token_fingerprint' => $this->fingerprint((string) $this->comparisonSystemToken),
                'message_templates' => $comparison,
            ];

            if ($comparison['success']) {
                $classification .= '_ONBOARDING_TOKEN_ONLY';
                $message .= ' The configured central system-user token succeeds on the same WABA, which confirms that the endpoint and WABA ID are valid and that the missing access is specific to the token issued by Embedded Signup.';
            } else {
                $message .= ' The configured central system-user token also failed, so verify the WABA ID and the asset assignment for both system users.';
            }
        }

        return $this->failure($classification, $message, $details);
    }

    private function probe(string $token, string $path, array $query = []): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withToken($token)
                ->get("https://graph.facebook.com/{$this->apiVersion}/{$path}", $query);

            $payload = $response->json();

            return [
                'success' => $response->successful() && ! isset($payload['error']),
                'http_status' => $response->status(),
                'graph_error' => $this->extractGraphError($payload, $response->status()),
                'request_id' => $response->header('x-fb-request-id'),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'http_status' => null,
                'graph_error' => [
                    'message' => 'Graph API request failed before a response was received.',
                    'exception' => $exception::class,
                ],
                'request_id' => null,
            ];
        }
    }

    private function normaliseScopes(array $data): array
    {
        $scopes = array_filter(array_map('strval', $data['scopes'] ?? []));

        foreach ($data['granular_scopes'] ?? [] as $scope) {
            $name = $scope['scope'] ?? $scope['permission'] ?? null;
            if ($name !== null) {
                $scopes[] = (string) $name;
            }
        }

        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return $scopes;
    }

    private function sanitiseGranularScopes(mixed $granularScopes): array
    {
        if (! is_array($granularScopes)) {
            return [];
        }

        return array_values(array_map(static function ($scope) {
            return [
                'scope' => $scope['scope'] ?? $scope['permission'] ?? null,
                'target_ids' => array_values(array_map('strval', $scope['target_ids'] ?? [])),
            ];
        }, array_filter($granularScopes, 'is_array')));
    }

    private function extractGraphError(mixed $payload, ?int $httpStatus): ?array
    {
        if (! is_array($payload)) {
            return $httpStatus !== null && $httpStatus >= 400
                ? ['http_status' => $httpStatus, 'message' => 'Meta returned a non-JSON error response.']
                : null;
        }

        $error = $payload['error'] ?? $payload['data']['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        return [
            'http_status' => $httpStatus,
            'code' => $error['code'] ?? null,
            'error_subcode' => $error['error_subcode'] ?? null,
            'type' => $error['type'] ?? null,
            'message' => $error['message'] ?? null,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ];
    }

    private function failure(string $classification, string $message, array $details): array
    {
        return [
            'success' => false,
            'classification' => $classification,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }
}
