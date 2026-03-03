<?php

namespace Modules\MetaFlows\Services;

use App\Models\Organization;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    private string $accessToken;
    private string $refreshToken;
    private int    $organizationId;

    public function __construct(string $accessToken, string $refreshToken, int $organizationId)
    {
        $this->accessToken    = $accessToken;
        $this->refreshToken   = $refreshToken;
        $this->organizationId = $organizationId;
    }

    // Build a service instance from the organization's stored Google OAuth tokens.
    // Proactively refreshes the access token if it has expired or expires within
    // the next 5 minutes — prevents the first API call from failing when the token
    // has gone stale (Google tokens last 1 hour).
    public static function fromOrganization(int $orgId): self
    {
        $organization = Organization::findOrFail($orgId);
        $metadata     = json_decode($organization->metadata, true) ?? [];
        $google       = $metadata['google_oauth'] ?? [];

        $instance = new self(
            $google['access_token']  ?? '',
            $google['refresh_token'] ?? '',
            $orgId
        );

        $expiresAt = $google['token_expires_at'] ?? null;

        if ($expiresAt && \Carbon\Carbon::parse($expiresAt)->subMinutes(5)->isPast()) {
            Log::info('GoogleSheetsService: token expired or expiring soon — proactive refresh', [
                'organization_id' => $orgId,
                'token_expires_at' => $expiresAt,
            ]);
            $instance->refreshAccessToken();
        }

        return $instance;
    }

    // Extract the spreadsheet ID from a Google Sheets URL
    public static function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // Fetch row 1 of the given tab and return the cell values as column headers
    public function getSheetHeaders(string $spreadsheetId, string $tab): array
    {
        $range    = urlencode($tab . '!1:1');
        $url      = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}";
        $response = $this->apiGet($url);

        $values = $response['values'][0] ?? [];

        return array_values(array_filter($values, fn($v) => $v !== ''));
    }

    // Append a single row to the given tab in the spreadsheet.
    // On a 401 response the access token is refreshed and the request is retried once.
    public function appendRow(string $spreadsheetId, string $tab, array $values): void
    {
        $range = urlencode($tab);
        $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}:append"
               . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $body = ['values' => [$values]];

        try {
            $this->apiPost($url, $body);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '401')) {
                $this->refreshAccessToken();
                $this->apiPost($url, $body);
            } else {
                throw $e;
            }
        }

        Log::info('GoogleSheetsService: row appended', [
            'organization_id' => $this->organizationId,
            'spreadsheet_id'  => $spreadsheetId,
            'tab'             => $tab,
        ]);
    }

    // Call the Google OAuth token endpoint to obtain a new access token
    private function refreshAccessToken(): void
    {
        $client   = new Client();
        $response = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id'     => config('services.google_sheets.client_id'),
                'client_secret' => config('services.google_sheets.client_secret'),
                'refresh_token' => $this->refreshToken,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        $newAccessToken = $data['access_token'] ?? null;

        if (!$newAccessToken) {
            throw new \RuntimeException('GoogleSheetsService: failed to refresh access token');
        }

        $this->accessToken = $newAccessToken;

        // Persist the refreshed token back to the organization metadata
        $organization = Organization::findOrFail($this->organizationId);
        $metadata     = json_decode($organization->metadata, true) ?? [];

        $metadata['google_oauth']['access_token']      = $newAccessToken;
        $metadata['google_oauth']['token_expires_at']  = now()->addSeconds($data['expires_in'] ?? 3600)->toIso8601String();

        $organization->update(['metadata' => json_encode($metadata)]);

        Log::info('GoogleSheetsService: access token refreshed', ['organization_id' => $this->organizationId]);
    }

    // Perform an authenticated GET request to the Sheets API.
    // On a 401 response the access token is refreshed and the request is retried once,
    // mirroring the same pattern used in appendRow().
    private function apiGet(string $url): array
    {
        $client = new Client();

        try {
            $response = $client->get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '401')) {
                Log::info('GoogleSheetsService: 401 on GET — refreshing token and retrying', [
                    'organization_id' => $this->organizationId,
                    'url'             => $url,
                ]);
                $this->refreshAccessToken();
                $response = $client->get($url, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
                ]);
            } else {
                throw $e;
            }
        }

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    // Perform an authenticated POST request to the Sheets API
    private function apiPost(string $url, array $body): array
    {
        $client   = new Client();
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 401) {
            throw new \RuntimeException('401 Unauthorized — access token expired');
        }

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
