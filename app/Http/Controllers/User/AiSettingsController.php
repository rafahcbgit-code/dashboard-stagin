<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Services\AiSettingsService;
use Illuminate\Http\Request;

class AiSettingsController extends BaseController
{
    private AiSettingsService $aiSettingsService;

    public function __construct(AiSettingsService $aiSettingsService)
    {
        $this->aiSettingsService = $aiSettingsService;
    }

    public function index(Request $request)
    {
        return redirect()->route('bot-settings.index');
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'ai_enabled' => 'nullable|boolean',
            'ai_only_mode' => 'nullable|boolean',
            'default_confidence_threshold' => 'nullable|integer|min:0|max:100',
        ]);

        $validated['ai_enabled'] = isset($validated['ai_enabled']) ? (bool) $validated['ai_enabled'] : false;
        $validated['ai_only_mode'] = isset($validated['ai_only_mode']) ? (bool) $validated['ai_only_mode'] : false;

        $organizationId = session()->get('current_organization');
        $this->aiSettingsService->update($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'AI settings updated successfully',
        ]);
    }
}