<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\AiKnowledgeBase;
use App\Models\Organization;
use App\Services\BotSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\FlowBuilder\Models\Flow;

class BotSettingsController extends BaseController
{
    private BotSettingsService $botSettingsService;

    public function __construct(BotSettingsService $botSettingsService)
    {
        $this->botSettingsService = $botSettingsService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $settings = $this->botSettingsService->get($organizationId);

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

        // Get flows for fallback menu dropdowns
        $flows = Flow::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->select('id', 'name')
            ->get();

        return Inertia::render('User/Automation/BotSettings/Index', [
            'title' => __('Bot Settings'),
            'settings' => $settings,
            'workingHours' => $workingHours,
            'kbEntryCount' => $kbEntryCount,
            'flows' => $flows,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'fallback_threshold' => 'sometimes|integer|min:1|max:20',
            'flow_timeout_minutes' => 'sometimes|integer|min:5|max:1440',
            'ai_fallback_enabled' => 'nullable|boolean',
            'ai_fallback_confidence' => 'nullable|integer|min:0|max:100',
            'fallback_menu_enabled' => 'nullable|boolean',
            'fallback_menu_message' => 'nullable|string|max:500',
            'fallback_menu_start_over_flow_id' => 'nullable|integer|exists:flows,id',
            'fallback_menu_help_flow_id' => 'nullable|integer|exists:flows,id',
            'fallback_menu_route_to_agent' => 'nullable|boolean',
        ]);

        // Convert checkboxes to booleans
        $validated['ai_fallback_enabled'] = isset($validated['ai_fallback_enabled']) ? (bool) $validated['ai_fallback_enabled'] : false;
        $validated['fallback_menu_enabled'] = isset($validated['fallback_menu_enabled']) ? (bool) $validated['fallback_menu_enabled'] : false;
        $validated['fallback_menu_route_to_agent'] = isset($validated['fallback_menu_route_to_agent']) ? (bool) $validated['fallback_menu_route_to_agent'] : false;

        $organizationId = session()->get('current_organization');
        $this->botSettingsService->update($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Bot settings updated successfully',
        ]);
    }
}