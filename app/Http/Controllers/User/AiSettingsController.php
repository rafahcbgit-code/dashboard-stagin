<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\AiKnowledgeBase;
use App\Models\Organization;
use App\Services\AiSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiSettingsController extends BaseController
{
    private AiSettingsService $aiSettingsService;

    public function __construct(AiSettingsService $aiSettingsService)
    {
        $this->aiSettingsService = $aiSettingsService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $settings = $this->aiSettingsService->get($organizationId);

        // KB entry count for summary
        $kbEntryCount = AiKnowledgeBase::forOrganization($organizationId)
            ->enabled()
            ->count();

        return Inertia::render('User/Automation/AiSettings/Index', [
            'title' => __('AI Settings'),
            'settings' => $settings,
            'kbEntryCount' => $kbEntryCount,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
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