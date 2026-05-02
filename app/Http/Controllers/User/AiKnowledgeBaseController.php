<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Services\AiKnowledgeBaseService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiKnowledgeBaseController extends BaseController
{
    private AiKnowledgeBaseService $kbService;

    public function __construct(AiKnowledgeBaseService $kbService)
    {
        $this->kbService = $kbService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $entries = $this->kbService->listAll($organizationId);

        return Inertia::render('User/Automation/KnowledgeBase/Index', [
            'title' => __('Knowledge Base'),
            'entries' => $entries,
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|max:2000',
            'answer' => 'required|string|max:5000',
            'is_enabled' => 'nullable|boolean',
        ]);

        $organizationId = session()->get('current_organization');
        $this->kbService->create($organizationId, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Knowledge entry added successfully',
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'question' => 'sometimes|string|max:2000',
            'answer' => 'sometimes|string|max:5000',
            'is_enabled' => 'nullable|boolean',
        ]);

        $this->kbService->update($uuid, $validated);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Knowledge entry updated successfully',
        ]);
    }

    public function destroy(string $uuid)
    {
        $this->kbService->delete($uuid);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Knowledge entry deleted successfully',
        ]);
    }

    public function toggle(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $this->kbService->toggleEnabled($uuid, $validated['is_enabled']);

        return redirect()->back()->with('status', [
            'type' => 'success',
            'message' => 'Entry status updated',
        ]);
    }
}