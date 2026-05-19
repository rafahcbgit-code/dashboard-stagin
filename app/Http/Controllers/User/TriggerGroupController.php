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

        // Check for duplicate keywords across other trigger groups
        if (!empty($validated['keywords']) && ($validated['type'] ?? '') === 'new_message') {
            $duplicates = $this->findDuplicateKeywords($validated['keywords'], $organizationId);
            if (!empty($duplicates)) {
                return redirect()->back()->withErrors([
                    'keywords' => 'Keyword(s) already exist in another trigger: ' . implode(', ', $duplicates)
                ])->withInput();
            }
        }

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

        // Check for duplicate keywords across other trigger groups (excluding self)
        $triggerGroup = TriggerGroup::where('uuid', $uuid)->first();
        $type = $validated['type'] ?? ($triggerGroup ? $triggerGroup->type : null);
        if (!empty($validated['keywords']) && $type === 'new_message') {
            $excludeId = $triggerGroup ? $triggerGroup->id : null;
            $organizationId = session()->get('current_organization');
            $duplicates = $this->findDuplicateKeywords($validated['keywords'], $organizationId, $excludeId);
            if (!empty($duplicates)) {
                return redirect()->back()->withErrors([
                    'keywords' => 'Keyword(s) already exist in another trigger: ' . implode(', ', $duplicates)
                ])->withInput();
            }
        }

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

    /**
     * Check if a keyword is available (not used in any other trigger group).
     * Returns JSON for API-style requests.
     */
    public function checkKeyword(Request $request)
    {
        $keyword = trim($request->query('keyword', ''));
        $excludeUuid = $request->query('exclude', '');

        if (empty($keyword)) {
            return response()->json(['available' => true]);
        }

        $organizationId = session()->get('current_organization');
        $excludeId = null;
        if ($excludeUuid) {
            $excludeTrigger = TriggerGroup::where('uuid', $excludeUuid)->first();
            $excludeId = $excludeTrigger ? $excludeTrigger->id : null;
        }

        $duplicates = $this->findDuplicateKeywords([$keyword], $organizationId, $excludeId);

        if (empty($duplicates)) {
            return response()->json(['available' => true]);
        }

        // Find which trigger owns the conflicting keyword
        $normalizedKeyword = mb_strtolower(trim($keyword), 'UTF-8');
        $conflictingTrigger = TriggerGroup::where('organization_id', $organizationId)
            ->where('type', 'new_message')
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->first(function ($tg) use ($normalizedKeyword) {
                $keywords = $tg->keywords ?? [];
                foreach ($keywords as $kw) {
                    if (mb_strtolower(trim($kw), 'UTF-8') === $normalizedKeyword) {
                        return true;
                    }
                }
                return false;
            });

        return response()->json([
            'available' => false,
            'trigger_name' => $conflictingTrigger ? $conflictingTrigger->name : null,
        ]);
    }

    /**
     * Find keywords that already exist in other trigger groups for the same organization.
     * Case-insensitive comparison. Excludes a specific trigger ID (for updates).
     *
     * @param array $keywords  The keywords to check
     * @param int $organizationId
     * @param int|null $excludeId  Trigger ID to exclude (self, on update)
     * @return array  List of conflicting keyword strings
     */
    private function findDuplicateKeywords(array $keywords, int $organizationId, ?int $excludeId = null): array
    {
        $duplicates = [];
        $existingKeywords = [];

        $triggers = TriggerGroup::where('organization_id', $organizationId)
            ->where('type', 'new_message')
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($triggers as $trigger) {
            $triggerKeywords = $trigger->keywords ?? [];
            foreach ($triggerKeywords as $kw) {
                $normalized = mb_strtolower(trim($kw), 'UTF-8');
                $existingKeywords[$normalized] = $trigger->name;
            }
        }

        foreach ($keywords as $keyword) {
            $normalized = mb_strtolower(trim($keyword), 'UTF-8');
            if (isset($existingKeywords[$normalized])) {
                $duplicates[] = $keyword;
            }
        }

        return $duplicates;
    }
}