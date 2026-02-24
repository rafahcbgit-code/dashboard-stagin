<?php

namespace Modules\MetaFlows\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleOAuthController extends Controller
{
    // Redirect the user to Google's OAuth consent screen.
    // The flow_uuid and org_id are embedded in a base64-encoded state parameter
    // for CSRF protection and so the callback knows where to redirect the user.
    public function connect(Request $request)
    {
        $flowUuid = $request->query('flow_uuid', '');
        $orgId    = session()->get('current_organization');

        $state = base64_encode(json_encode([
            'flow_uuid' => $flowUuid,
            'org_id'    => $orgId,
        ]));

        session()->put('google_oauth_state', $state);

        return Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/spreadsheets'])
            ->with([
                'access_type' => 'offline',
                'prompt'      => 'consent',
                'state'       => $state,
            ])
            ->redirect();
    }

    // Handle Google's callback after the user grants permission.
    // Validates the state, exchanges the code for tokens, and persists
    // access_token + refresh_token in the organization metadata.
    public function callback(Request $request)
    {
        $returnedState = $request->query('state', '');
        $sessionState  = session()->get('google_oauth_state', '');

        if ($returnedState !== $sessionState) {
            return redirect('/automation/meta-flows')->withErrors(['google' => 'OAuth state mismatch. Please try again.']);
        }

        session()->forget('google_oauth_state');

        $stateData = json_decode(base64_decode($returnedState), true);
        $orgId     = $stateData['org_id']    ?? session()->get('current_organization');
        $flowUuid  = $stateData['flow_uuid'] ?? '';

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            return redirect('/automation/meta-flows')->withErrors(['google' => 'Failed to authenticate with Google: ' . $e->getMessage()]);
        }

        $organization = Organization::findOrFail($orgId);
        $metadata     = json_decode($organization->metadata, true) ?? [];

        $metadata['google_oauth'] = [
            'access_token'     => $googleUser->token,
            'refresh_token'    => $googleUser->refreshToken,
            'google_email'     => $googleUser->getEmail(),
            'connected_at'     => now()->toIso8601String(),
            'token_expires_at' => now()->addSeconds($googleUser->expiresIn ?? 3600)->toIso8601String(),
        ];

        $organization->update(['metadata' => json_encode($metadata)]);

        $redirect = $flowUuid
            ? '/automation/meta-flows/' . $flowUuid
            : '/automation/meta-flows';

        return redirect($redirect)->with('status', [
            'type'    => 'success',
            'message' => 'Google account connected successfully.',
        ]);
    }

    // Remove the stored Google OAuth credentials from the organization metadata
    public function disconnect(Request $request)
    {
        $orgId        = session()->get('current_organization');
        $organization = Organization::findOrFail($orgId);
        $metadata     = json_decode($organization->metadata, true) ?? [];

        unset($metadata['google_oauth']);

        $organization->update(['metadata' => json_encode($metadata)]);

        return response()->json(['message' => 'Google account disconnected.']);
    }
}
