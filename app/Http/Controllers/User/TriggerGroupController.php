<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\TriggerGroup;
use App\Services\AiSettingsService;
use App\Services\TriggerGroupService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TriggerGroupController extends BaseController
{
    private TriggerGroupService $triggerGroupService;

    public function __construct(TriggerGroupService $triggerGroupService)
    {
        $this->triggerGroupService = $triggerGroupService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        // Ensure system triggers exist
        $this->triggerGroupService->initializeSystemTriggers($organizationId);

        $searchTerm = $request->query('search');
        $triggerGroups = $this->triggerGroupService->listAll($organizationId, $searchTerm);

        // Get available flows for linking
        $flows = \Modules\FlowBuilder\Models\Flow::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->select('id', 'uuid', 'name', 'status')
            ->orderBy('name')
            ->get();

        // Check if AI-Only Mode is active for UI feedback
        $aiSettingsService = new AiSettingsService();
        $aiOnlyMode = $aiSettingsService->isAiOnlyMode($organizationId);

        return Inertia::render('User/Automation/Triggers/Index', [
            'title' => __('Triggers'),
            'triggerGroups' => $triggerGroups,
            'flows' => $flows,
            'filters' => request()->all(),
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
            'aiOnlyMode' => $aiOnlyMode,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:new_contact,new_message,greeting,away,fallback',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:255',
            'match_mode' => 'nullable|in:exact,ai',
            'ai_confidence' => 'nullable|integer|min:0|max:100',
            'flow_id' => 'nullable|exists:flows,id',
            'rate_limit_type' => 'nullable|in:none,lifetime,24h',
        ]);

        $organizationId = session()->get('current_organization');
        $validated['match_mode'] = $validated['match_mode'] ?? 'exact';
        $validated['ai_confidence'] = $validated['ai_confidence'] ?? 75;
        $validated['rate_limit_type'] = $validated['rate_limit_type'] ?? 'none';

        $this->triggerGroupService->create($organizationId, $validated);

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Trigger created successfully']);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:new_contact,new_message,greeting,away,fallback',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string|max:255',
            'match_mode' => 'nullable|in:exact,ai',
            'ai_confidence' => 'nullable|integer|min:0|max:100',
            'flow_id' => 'nullable|exists:flows,id',
            'rate_limit_type' => 'nullable|in:none,lifetime,24h',
            'is_enabled' => 'nullable|boolean',
        ]);

        $this->triggerGroupService->update($uuid, $validated);

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Trigger updated successfully']);
    }

    public function destroy(string $uuid)
    {
        $deleted = $this->triggerGroupService->delete($uuid);

        if (!$deleted) {
            return redirect()->back()->withErrors('Cannot delete system triggers. Disable them instead.');
        }

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Trigger deleted successfully']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ordered_uuids' => 'required|array',
            'ordered_uuids.*' => 'required|string|exists:trigger_groups,uuid',
        ]);

        $organizationId = session()->get('current_organization');
        $this->triggerGroupService->reorder($organizationId, $validated['ordered_uuids']);

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Trigger priority updated']);
    }

    public function toggle(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $this->triggerGroupService->toggleEnabled($uuid, $validated['is_enabled']);

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Trigger status updated']);
    }

    public function linkFlow(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'flow_id' => 'required|exists:flows,id',
        ]);

        $this->triggerGroupService->linkFlow($uuid, $validated['flow_id']);

        return redirect()->back()->with('status', ['type' => 'success', 'message' => 'Flow linked to trigger']);
    }
}