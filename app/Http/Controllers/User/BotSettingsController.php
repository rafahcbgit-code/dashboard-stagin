<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\AiKnowledgeBase;
use App\Models\Organization;
use App\Services\AiSettingsService;
use App\Services\BotSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\FlowBuilder\Models\Flow;

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

        $flows = Flow::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'name', 'status')
            ->orderBy('name')
            ->get();

        return Inertia::render('User/Automation/BotSettings/Index', [
            'title' => __('Behavior & AI'),
            'settings' => $settings,
            'aiSettings' => $aiSettings,
            'workingHours' => $workingHours,
            'kbEntryCount' => $kbEntryCount,
            'flows' => $flows,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function update(Request $request)
    {
        $organizationId = session()->get('current_organization');

        $validated = $request->validate([
            // Bot settings
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
            'flow_rejoin_reminder_minutes' => 'nullable|integer|min:5|max:1440',
            'flow_rejoin_message' => 'nullable|string|max:500',
            'flow_rejoin_flow_ids' => 'nullable|array',
            'flow_rejoin_flow_ids.*' => [
                'integer',
                Rule::exists('flows', 'id')->where(function ($query) use ($organizationId) {
                    return $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            // AI settings
            'ai_enabled' => 'nullable|boolean',
            'ai_only_mode' => 'nullable|boolean',
            'default_confidence_threshold' => 'nullable|integer|min:0|max:100',
        ]);

        // Convert checkboxes to booleans
        $validated['automation_enabled'] = isset($validated['automation_enabled']) ? (bool) $validated['automation_enabled'] : false;
        $validated['ai_fallback_enabled'] = isset($validated['ai_fallback_enabled']) ? (bool) $validated['ai_fallback_enabled'] : false;
        $validated['escalation_transfer_message_enabled'] = isset($validated['escalation_transfer_message_enabled']) ? (bool) $validated['escalation_transfer_message_enabled'] : false;
        $validated['ai_followup_enabled'] = isset($validated['ai_followup_enabled']) ? (bool) $validated['ai_followup_enabled'] : false;
        $validated['flow_rejoin_enabled'] = isset($validated['flow_rejoin_enabled']) ? (bool) $validated['flow_rejoin_enabled'] : false;
        $validated['ai_enabled'] = isset($validated['ai_enabled']) ? (bool) $validated['ai_enabled'] : false;
        $validated['ai_only_mode'] = isset($validated['ai_only_mode']) ? (bool) $validated['ai_only_mode'] : false;

        $validated['flow_rejoin_flow_ids'] = array_values(array_unique(array_map(
            'intval',
            $validated['flow_rejoin_flow_ids'] ?? []
        )));

        if ($validated['flow_rejoin_enabled'] && empty($validated['flow_rejoin_flow_ids'])) {
            $validated['flow_rejoin_enabled'] = false;
        }

        // Split into bot/AI settings and save atomically
        $botKeys = [
            'automation_enabled', 'fallback_threshold', 'flow_timeout_minutes',
            'ai_fallback_enabled', 'ai_fallback_confidence',
            'escalation_transfer_message_enabled', 'escalation_transfer_message',
            'ai_followup_enabled', 'ai_followup_question', 'ai_followup_yes_text', 'ai_followup_no_text',
            'flow_rejoin_enabled', 'flow_rejoin_reminder_minutes', 'flow_rejoin_message', 'flow_rejoin_flow_ids',
        ];
        $aiKeys = ['ai_enabled', 'ai_only_mode', 'default_confidence_threshold'];

        $botSettings = array_intersect_key($validated, array_flip($botKeys));
        $aiSettings = array_intersect_key($validated, array_flip($aiKeys));

        $this->botSettingsService->update($organizationId, $botSettings);
        $this->aiSettingsService->update($organizationId, $aiSettings);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Settings updated successfully',
        ]);
    }
}
