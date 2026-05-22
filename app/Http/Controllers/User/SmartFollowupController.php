<?php

namespace App\Http\Controllers\User;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Models\SmartFollowup;
use App\Services\SmartFollowupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\FlowBuilder\Models\Flow;

class SmartFollowupController extends BaseController
{
    public function __construct(private SmartFollowupService $smartFollowupService)
    {
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $rejoinReminders = $this->smartFollowupService->listRejoinReminders($organizationId);

        $flows = Flow::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->select('id', 'uuid', 'name', 'status')
            ->orderBy('name')
            ->get();

        return Inertia::render('User/Automation/SmartFollowup/Index', [
            'title' => __('Smart Followup'),
            'rejoinReminders' => $rejoinReminders,
            'flows' => $flows,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function store(Request $request)
    {
        $organizationId = session()->get('current_organization');

        $validated = $request->validate([
            'flow_id' => [
                'required',
                'integer',
                Rule::exists('flows', 'id')->where(function ($query) use ($organizationId) {
                    return $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
                Rule::unique('smart_followups', 'flow_id')->where(function ($query) use ($organizationId) {
                    return $query
                        ->where('organization_id', $organizationId)
                        ->where('type', SmartFollowup::TYPE_REJOIN_REMINDER);
                }),
            ],
            'message' => 'required|string|max:500',
            'reminder_minutes' => 'required|integer|min:5|max:1440',
            'is_enabled' => 'nullable|boolean',
        ]);

        $this->smartFollowupService->createRejoinReminder($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Rejoin reminder created successfully',
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'reminder_minutes' => 'required|integer|min:5|max:1440',
            'is_enabled' => 'nullable|boolean',
        ]);

        $organizationId = session()->get('current_organization');
        $this->smartFollowupService->updateRejoinReminder($organizationId, $uuid, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Rejoin reminder updated successfully',
        ]);
    }

    public function destroy(string $uuid)
    {
        $organizationId = session()->get('current_organization');
        $this->smartFollowupService->delete($organizationId, $uuid);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Rejoin reminder deleted successfully',
        ]);
    }

    public function toggle(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $organizationId = session()->get('current_organization');
        $this->smartFollowupService->toggle($organizationId, $uuid, (bool) $validated['is_enabled']);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Rejoin reminder status updated',
        ]);
    }
}
