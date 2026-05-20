<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\AiKnowledgeBase;
use App\Models\Organization;
use App\Services\AiSettingsService;
use App\Services\BotSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BotSettingsController extends BaseController
{
    private BotSettingsService $botSettingsService;
    private AiSettingsService $aiSettingsService;

    public function __construct(BotSettingsService $botSettingsService, AiSettingsService $aiSettingsService)
    {
        $this->botSettingsService = $botSettingsService;
        $this->aiSettingsService = $aiSettingsService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $settings = $this->botSettingsService->get($organizationId);
        $aiSettings = $this->aiSettingsService->get($organizationId);

        // Read working hours from org metadata (read-only for display)
        $org = Organization::find($organizationId);
        $metadata = $org && $org->metadata ? json_decode($org->metadata, true) : [];
        $workingHours = [
            'enabled' => (bool) ($metadata['enable_working_hours'] ?? false),
            'schedule' => $metadata['working_hours'] ?? [],
            'timezone' => $metadata['timezone'] ?? config('app.timezone', 'UTC'),
        ];

        // Count enabled KB entries for the AI fallback warning
        $kbEntryCount = AiKnowledgeBase::forOrganization($organizationId)
            ->enabled()
            ->count();

        return Inertia::render('User/Automation/BotSettings/Index', [
            'title' => __('Behavior & AI'),
            'settings' => $settings,
            'aiSettings' => $aiSettings,
            'workingHours' => $workingHours,
            'kbEntryCount' => $kbEntryCount,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'automation_enabled' => 'nullable|boolean',
            'fallback_threshold' => 'sometimes|integer|min:1|max:20',
            'flow_timeout_minutes' => 'sometimes|integer|min:5|max:1440',
            'ai_fallback_enabled' => 'nullable|boolean',
            'ai_fallback_confidence' => 'nullable|integer|min:0|max:100',
            'escalation_transfer_message_enabled' => 'nullable|boolean',
            'escalation_transfer_message' => 'nullable|string|max:500',
            'ai_followup_enabled' => 'nullable|boolean',
            'ai_followup_question' => 'nullable|string|max:60',
            'ai_followup_yes_text' => 'nullable|string|max:20',
            'ai_followup_no_text' => 'nullable|string|max:20',
            'flow_rejoin_enabled' => 'nullable|boolean',
            'flow_rejoin_reminder_minutes' => 'nullable|integer|min:1|max:1440',
            'flow_rejoin_message' => 'nullable|string|max:500',
        ]);

        // Convert checkboxes to booleans
        $validated['automation_enabled'] = isset($validated['automation_enabled']) ? (bool) $validated['automation_enabled'] : false;
        $validated['ai_fallback_enabled'] = isset($validated['ai_fallback_enabled']) ? (bool) $validated['ai_fallback_enabled'] : false;
        $validated['escalation_transfer_message_enabled'] = isset($validated['escalation_transfer_message_enabled']) ? (bool) $validated['escalation_transfer_message_enabled'] : false;
        $validated['ai_followup_enabled'] = isset($validated['ai_followup_enabled']) ? (bool) $validated['ai_followup_enabled'] : false;
        $validated['flow_rejoin_enabled'] = isset($validated['flow_rejoin_enabled']) ? (bool) $validated['flow_rejoin_enabled'] : false;
        $validated['ai_followup_enabled'] = isset($validated['ai_followup_enabled']) ? (bool) $validated['ai_followup_enabled'] : false;

        $organizationId = session()->get('current_organization');
        $this->botSettingsService->update($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Bot settings updated successfully',
        ]);
    }
}