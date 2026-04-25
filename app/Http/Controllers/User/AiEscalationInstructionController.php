<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\Team;
use App\Services\AiEscalationInstructionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiEscalationInstructionController extends BaseController
{
    private AiEscalationInstructionService $escalationService;

    public function __construct(AiEscalationInstructionService $escalationService)
    {
        $this->escalationService = $escalationService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $instructions = $this->escalationService->listAll($organizationId);

        // Get agents for specific_agent target
        $agents = Team::with('user')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($team) {
                return [
                    'id' => $team->user->id,
                    'name' => $team->user->first_name . ' ' . $team->user->last_name,
                ];
            });

        return Inertia::render('User/Automation/Escalation/Index', [
            'title' => __('Escalation Instructions'),
            'instructions' => $instructions,
            'agents' => $agents,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            'target_type' => 'required|in:auto_assignment,specific_agent',
            'target_agent_id' => 'nullable|exists:users,id',
            'is_enabled' => 'nullable|boolean',
        ]);

        if (($validated['target_type'] ?? null) === 'specific_agent' && empty($validated['target_agent_id'])) {
            return redirect()->back()->withErrors(['target_agent_id' => 'Please select an agent when routing to a specific agent.'])->withInput();
        }

        $organizationId = session()->get('current_organization');
        $this->escalationService->create($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Escalation instruction created successfully',
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:2000',
            'target_type' => 'sometimes|in:auto_assignment,specific_agent,bot_queue',
            'target_agent_id' => 'nullable|exists:users,id',
            'is_enabled' => 'nullable|boolean',
        ]);

        if (($validated['target_type'] ?? null) === 'specific_agent' && empty($validated['target_agent_id'])) {
            return redirect()->back()->withErrors(['target_agent_id' => 'Please select an agent when routing to a specific agent.'])->withInput();
        }

        $this->escalationService->update($uuid, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Escalation instruction updated successfully',
        ]);
    }

    public function destroy(string $uuid)
    {
        $this->escalationService->delete($uuid);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Escalation instruction deleted successfully',
        ]);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ordered_uuids' => 'required|array',
            'ordered_uuids.*' => 'required|string|exists:ai_escalation_instructions,uuid',
        ]);

        $organizationId = session()->get('current_organization');
        $this->escalationService->reorder($organizationId, $validated['ordered_uuids']);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Priority updated',
        ]);
    }

    public function toggle(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $this->escalationService->toggleEnabled($uuid, $validated['is_enabled']);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Instruction status updated',
        ]);
    }
}