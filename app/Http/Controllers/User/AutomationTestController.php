<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Services\AutomationSimulatorService;
use App\Services\AiSettingsService;
use App\Services\BotSettingsService;
use App\Services\WorkingHoursService;
use App\Models\TriggerGroup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\FlowBuilder\Models\Flow;

class AutomationTestController extends BaseController
{
    protected AutomationSimulatorService $simulatorService;

    public function __construct(AutomationSimulatorService $simulatorService)
    {
        $this->simulatorService = $simulatorService;
    }

    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        $organization = \App\Models\Organization::find($organizationId);
        $orgName = $organization ? ($organization->name ?? 'ChatBerry') : 'ChatBerry';

        $flows = Flow::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->select('id', 'uuid', 'name', 'status')
            ->orderBy('name')
            ->get();

        $botSettings = new BotSettingsService();
        $workingHours = new WorkingHoursService();
        $aiSettings = new AiSettingsService();

        return Inertia::render('User/Automation/Test/Index', [
            'title' => __('Automation Test'),
            'flows' => $flows,
            'orgName' => $orgName,
            'aiEnabled' => $aiSettings->isEnabled($organizationId),
            'aiFallbackEnabled' => (bool) $botSettings->getSetting($organizationId, 'ai_fallback_enabled', false),
            'withinWorkingHours' => $workingHours->isWithinWorkingHours($organizationId),
            'automationEnabled' => $botSettings->isAutomationEnabled($organizationId),
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function testTrigger(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'is_new_contact' => 'boolean',
            'during_working_hours' => 'boolean',
        ]);

        $organizationId = session()->get('current_organization');

        $result = $this->simulatorService->simulateTriggerEvaluation(
            $organizationId,
            $request->input('message'),
            $request->input('is_new_contact', false),
            $request->input('during_working_hours', true),
        );

        return response()->json($result);
    }

    public function startFlow(Request $request)
    {
        $request->validate([
            'flow_id' => 'required|integer',
        ]);

        $organizationId = session()->get('current_organization');

        $result = $this->simulatorService->startFlowSimulation(
            $organizationId,
            $request->input('flow_id'),
        );

        return response()->json($result);
    }

    public function stepFlow(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'user_input' => 'nullable|string|max:1000',
            'selected_option' => 'nullable|string',
        ]);

        $result = $this->simulatorService->advanceFlowSimulation(
            $request->input('session_id'),
            $request->input('user_input', ''),
            $request->input('selected_option'),
        );

        return response()->json($result);
    }

    public function testAi(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $organizationId = session()->get('current_organization');

        $result = $this->simulatorService->simulateAiResponse(
            $organizationId,
            $request->input('message'),
        );

        return response()->json($result);
    }

    public function getTestContext(Request $request)
    {
        $organizationId = session()->get('current_organization');

        $botSettings = new BotSettingsService();
        $aiSettings = new AiSettingsService();
        $workingHours = new WorkingHoursService();

        $triggers = TriggerGroup::forOrganization($organizationId)
            ->enabled()
            ->orderedByPriority()
            ->get()
            ->map(fn($tg) => [
                'id' => $tg->id,
                'name' => $tg->name,
                'type' => $tg->type,
                'priority' => $tg->priority ?? 0,
                'rate_limit_type' => $tg->rate_limit_type ?? 'none',
                'match_mode' => $tg->type === 'new_message' ? ($tg->match_mode ?? 'exact') : null,
                'keywords' => $tg->type === 'new_message' ? ($tg->keywords ?? []) : null,
            ]);

        $flows = Flow::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->select('id', 'uuid', 'name', 'status')
            ->orderBy('name')
            ->get();

        return response()->json([
            'bot_settings' => [
                'automation_enabled' => $botSettings->isAutomationEnabled($organizationId),
                'ai_enabled' => $aiSettings->isEnabled($organizationId),
                'ai_fallback_enabled' => (bool) $botSettings->getSetting($organizationId, 'ai_fallback_enabled', false),
                'ai_only_mode' => (bool) $aiSettings->isAiOnlyMode($organizationId),
                'fallback_threshold' => (int) $botSettings->getSetting($organizationId, 'fallback_threshold', 3),
                'flow_timeout_minutes' => (int) $botSettings->getSetting($organizationId, 'flow_timeout_minutes', 30),
                'escalation_transfer_enabled' => (bool) $botSettings->getSetting($organizationId, 'escalation_transfer_message_enabled', false),
                'ai_followup_enabled' => (bool) $botSettings->getSetting($organizationId, 'ai_followup_enabled', false),
                'flow_rejoin_enabled' => (bool) $botSettings->getSetting($organizationId, 'flow_rejoin_enabled', false),
            ],
            'ai_settings' => [
                'ai_enabled' => $aiSettings->isEnabled($organizationId),
                'confidence_threshold' => $aiSettings->getDefaultConfidence($organizationId),
            ],
            'working_hours' => [
                'enabled' => (bool) $botSettings->getSetting($organizationId, 'enable_working_hours', false),
                'within' => $workingHours->isWithinWorkingHours($organizationId),
                'schedule' => $workingHours->getSchedule($organizationId),
            ],
            'triggers' => $triggers,
            'flows' => $flows,
        ]);
    }
}