<?php

namespace Modules\FlowBuilder\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\Addon;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowSubmission;
use App\Models\Organization;
use App\Models\Setting;
use Modules\FlowBuilder\Services\FlowService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class FlowController extends BaseController
{
    protected $flowService;

    public function __construct(FlowService $flowService)
    {
        $this->flowService = $flowService;
    }

    /**
     * Display a listing of flows.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if(!CustomHelper::isModuleEnabled('Flow builder')){
            abort(404);
        }

        $data['title'] = __('Settings');
        $data['aimodule'] = CustomHelper::isModuleEnabled('AI Assistant');
        $data['fbmodule'] = CustomHelper::isModuleEnabled('Flow builder');
        $data['rows'] = $this->flowService->getRows($request);
        $data['filters'] = request()->all();

        return Inertia::render('FlowBuilder::User/Index', $data);
    }

    public function view(Request $request, $uuid)
    {
        $data['uuid'] = $uuid;
        $organizationId = session()->get('current_organization');
        $data['flow'] = Flow::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->withCount('submissions')
            ->firstOrFail();
        
        // Get contact groups for the current organization
        $contactGroups = \App\Models\ContactGroup::where('organization_id', $organizationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function($group) {
                return [
                    'value' => $group->id,
                    'label' => $group->name
                ];
            });

        // Get labels for the current organization
        $labels = \App\Models\Label::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get()
            ->map(function($label) {
                return [
                    'value' => $label->id,
                    'label' => $label->title,
                    'color' => $label->color,
                ];
            });

        // Get contact fields for the current organization
        $contactFields = \App\Models\ContactField::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get()
            ->map(function($field) {
                return [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type,
                    'value' => $field->value,
                    'required' => $field->required
                ];
            });

        // Get available actions from config file
        $actionsConfig = config('FlowBuilder.actions', []);
        $availableActions = [];
        foreach ($actionsConfig as $action => $enabled) {
            if ($enabled === true) {
                $availableActions[] = $action;
            }
        }

        // Get approved templates for the current organization
        $templates = \App\Models\Template::where('organization_id', $organizationId)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(function($template) {
                return [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'language' => $template->language,
                    'category' => $template->category,
                ];
            });

        // Get published meta flows for the current organization
        $metaFlows = \Modules\MetaFlows\Models\MetaFlow::where('organization_id', $organizationId)
            ->where('status', 'published')
            ->whereNotNull('meta_flow_id')
            ->whereNotNull('asset_content')
            ->orderBy('name')
            ->get()
            ->map(fn($flow) => [
                'uuid' => $flow->uuid,
                'name' => $flow->name,
                'meta_flow_id' => $flow->meta_flow_id,
            ]);

        $data['contactGroups'] = $contactGroups;
        $data['contactFields'] = $contactFields;
        $data['labels'] = $labels;
        $data['templates'] = $templates;
        $data['metaFlows'] = $metaFlows;

        // Get active flows for Portal node (exclude current flow)
        $activeFlows = Flow::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('id', '!=', $data['flow']->id)
            ->orderBy('name')
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'uuid' => $f->uuid,
                'name' => $f->name,
            ]);
        $data['activeFlows'] = $activeFlows;

        $data['availableActions'] = $availableActions;

        return Inertia::render('FlowBuilder::User/View', $data);
    }

    public function responses(Request $request, string $uuid)
    {
        $this->validateResponsesRequest($request);

        $organizationId = session()->get('current_organization');
        $flow = Flow::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $questions = $this->extractQuestionDefinitions($flow);
        $baseQuery = $this->buildResponsesQuery($request, $flow, $organizationId);
        $runs = $this->buildSubmissionRuns(
            (clone $baseQuery)->orderByDesc('created_at')->get(),
            $questions['by_node']
        );
        $responses = $this->paginateRuns($runs, $request);

        $statsQuery = FlowSubmission::where('organization_id', $organizationId)
            ->where('flow_id', $flow->id);
        $allRuns = $this->buildSubmissionRuns((clone $statsQuery)->with('contact')->get(), $questions['by_node']);

        return Inertia::render('FlowBuilder::User/Responses', [
            'flow' => [
                'uuid' => $flow->uuid,
                'name' => $flow->name,
                'status' => $flow->status,
            ],
            'responses' => $responses,
            'filters' => $request->only(['search', 'variable', 'status', 'date_from', 'date_to']),
            'questions' => $questions['list'],
            'stats' => [
                'total_answers' => (clone $statsQuery)->where('status', 'answered')->count(),
                'total_submissions' => count($allRuns),
                'unique_contacts' => (clone $statsQuery)->whereNotNull('contact_id')->distinct('contact_id')->count('contact_id'),
                'question_variables' => count($questions['list']),
                'invalid_attempts' => (clone $statsQuery)->whereIn('status', ['invalid', 'max_retries_exceeded'])->count(),
                'completion_rate' => $this->calculateCompletionRate($allRuns, count($questions['list'])),
                'latest_answer_at' => optional((clone $statsQuery)->latest()->first())->created_at?->toDateTimeString(),
            ],
        ]);
    }

    public function exportResponses(Request $request, string $uuid)
    {
        $this->validateResponsesRequest($request);

        $organizationId = session()->get('current_organization');
        $flow = Flow::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $questions = $this->extractQuestionDefinitions($flow);
        $filename = preg_replace('/[^A-Za-z0-9\-_]+/', '-', strtolower($flow->name ?: 'flow'));
        $filename = trim($filename, '-') ?: 'flow';
        $filename .= '-question-responses.csv';

        $runs = $this->buildSubmissionRuns(
            $this->buildResponsesQuery($request, $flow, $organizationId)->orderByDesc('created_at')->get(),
            $questions['by_node']
        );

        return response()->streamDownload(function () use ($runs, $questions) {
            $handle = fopen('php://output', 'w');
            $questionVariables = array_values(array_unique(array_filter(array_map(
                fn ($question) => $question['variable_name'] ?? null,
                $questions['list']
            ))));

            fputcsv($handle, [
                'Submitted At',
                'Contact Name',
                'Phone',
                'Email',
                'Status',
                'Invalid Attempts',
                ...$questionVariables,
            ]);

            foreach ($runs as $run) {
                $answers = collect($run['answers'])->keyBy('variable_name');
                fputcsv($handle, [
                    $run['submitted_at'],
                    $run['contact']['name'] ?? '',
                    $run['contact']['phone'] ?? '',
                    $run['contact']['email'] ?? '',
                    $run['status'],
                    $run['invalid_attempts'],
                    ...array_map(fn ($variable) => $answers[$variable]['answer_value'] ?? '', $questionVariables),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Store a newly created flow in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $flow = $this->flowService->createFlow($data);

        return redirect('/automation/flows/'.$flow->uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation created successfully!')
            ]
        );
    }

    public function update(Request $request, $uuid)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'metadata' => 'nullable|json', // Assuming metadata is an array; adjust if necessary.
            'publish' => 'sometimes|boolean'
        ]);

        $flow = Flow::where('uuid', $uuid)->firstOrFail();

        $data = [
            'name' => $data['name'] ?? $flow->name,
            'description' => $data['description'] ?? $flow->description,
            'metadata' => $data['metadata'] ?? $flow->metadata,
        ];

        $flow = $this->flowService->updateFlow($uuid, $data, $request->publish);

        // Check if the request expects JSON response
        if (request()->expectsJson()) {
            return response()->json($flow);
        }

        // If it's an Inertia request, redirect back
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation updated successfully!')
            ]
        );
    }

    public function duplicate(Request $request, $uuid)
    {
        $flow = Flow::where('uuid', $uuid)->firstOrFail();

        if ($flow->status !== 'active') {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('Only published flows can be copied.')
                ]
            );
        }

        $this->flowService->duplicateFlow($uuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success',
                'message' => __('Flow copied successfully!')
            ]
        );
    }

    public function uploadMedia(Request $request, $uuid, $stepId)
    {
        $flow = $this->flowService->uploadMedia($request, $uuid, $stepId);

        return response()->json($flow);
    }

    /**
     * Remove the specified flow from storage.
     *
     * @param Flow $flow
     * @return \Illuminate\Http\Response
     */
    public function destroy($uuid)
    {
        $this->flowService->deleteFlow($uuid);
        
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation deleted successfully!')
            ]
        );
    }

    public function debug($uuid){
        return response()->json($this->flowService->debugFlow($uuid));
    }

    private function buildResponsesQuery(Request $request, Flow $flow, int $organizationId)
    {
        $query = FlowSubmission::query()
            ->with(['contact:id,uuid,organization_id,first_name,last_name,phone,email'])
            ->where('organization_id', $organizationId)
            ->where('flow_id', $flow->id);

        if ($request->filled('variable')) {
            $variable = $request->string('variable')->toString();
            $query->where(function ($inner) use ($variable) {
                $inner->where('variable_name', $variable)
                    ->orWhere('submission_data->variable_name', $variable);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from')->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to')->toDateString());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->whereHas('contact', function ($contactQuery) use ($search, $organizationId) {
                $contactQuery->where('organization_id', $organizationId)
                    ->where(function ($inner) use ($search) {
                        $inner->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%' . $search . '%'])
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        return $query;
    }

    private function validateResponsesRequest(Request $request): void
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'variable' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:answered,invalid,max_retries_exceeded',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
    }

    private function extractQuestionDefinitions(Flow $flow): array
    {
        $metadata = is_string($flow->metadata) ? json_decode($flow->metadata, true) : $flow->metadata;
        $questionsByNode = [];
        $questions = [];

        foreach (($metadata['nodes'] ?? []) as $node) {
            if (($node['type'] ?? null) !== 'question') {
                continue;
            }

            $fields = data_get($node, 'data.metadata.fields', []);
            $nodeId = (string) ($node['id'] ?? '');
            if ($nodeId === '') {
                continue;
            }

            $definition = [
                'node_id' => $nodeId,
                'question_text' => $fields['question_text'] ?? '',
                'variable_name' => $fields['variable_name'] ?? '',
                'input_type' => $fields['input_type'] ?? 'text',
            ];

            $questionsByNode[$nodeId] = $definition;

            if (!empty($definition['variable_name'])) {
                $questions[$definition['variable_name']] = $definition;
            }
        }

        return [
            'by_node' => $questionsByNode,
            'list' => array_values($questions),
        ];
    }

    private function buildSubmissionRuns($submissions, array $questionsByNode): array
    {
        return $submissions
            ->groupBy(fn ($submission) => $submission->run_uuid ?: 'legacy-' . $submission->id)
            ->map(function ($items, $runUuid) use ($questionsByNode) {
                $items = $items->sortBy('created_at')->values();
                $first = $items->first();
                $latest = $items->sortByDesc('created_at')->first();
                $answers = $items
                    ->filter(fn ($submission) => $this->submissionStatus($submission) === 'answered')
                    ->map(fn ($submission) => $this->formatAnswer($submission, $questionsByNode))
                    ->values()
                    ->all();
                $attempts = $items
                    ->filter(fn ($submission) => $this->submissionStatus($submission) !== 'answered')
                    ->map(fn ($submission) => $this->formatAnswer($submission, $questionsByNode))
                    ->values()
                    ->all();

                return [
                    'id' => (string) $runUuid,
                    'submitted_at' => $latest?->created_at?->toDateTimeString(),
                    'started_at' => $first?->created_at?->toDateTimeString(),
                    'status' => $this->runStatus($answers, $attempts),
                    'answers_count' => count($answers),
                    'invalid_attempts' => count($attempts),
                    'answers' => $answers,
                    'attempts' => $attempts,
                    'contact' => $this->formatContact($first?->contact),
                ];
            })
            ->sortByDesc('submitted_at')
            ->values()
            ->all();
    }

    private function paginateRuns(array $runs, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 20;

        return new LengthAwarePaginator(
            array_slice($runs, ($page - 1) * $perPage, $perPage),
            count($runs),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function calculateCompletionRate(array $runs, int $questionCount): int
    {
        if ($questionCount <= 0 || count($runs) === 0) {
            return 0;
        }

        $completed = collect($runs)->filter(fn ($run) => ($run['answers_count'] ?? 0) >= $questionCount)->count();

        return (int) round(($completed / count($runs)) * 100);
    }

    private function formatAnswer(FlowSubmission $submission, array $questionsByNode): array
    {
        $data = $submission->submission_data ?? [];
        $nodeId = (string) ($submission->node_id ?? $data['node_id'] ?? '');
        $question = $questionsByNode[$nodeId] ?? [];

        return [
            'id' => $submission->id,
            'submitted_at' => $submission->answered_at?->toDateTimeString() ?? $submission->created_at?->toDateTimeString(),
            'node_id' => $nodeId,
            'question_text' => $submission->question_text ?? $data['question_text'] ?? $question['question_text'] ?? '',
            'variable_name' => $submission->variable_name ?? $data['variable_name'] ?? ($question['variable_name'] ?? ''),
            'input_type' => $submission->input_type ?? $data['input_type'] ?? ($question['input_type'] ?? ''),
            'answer_value' => $submission->answer_value ?? $data['value'] ?? '',
            'status' => $this->submissionStatus($submission),
            'submission_data' => $data,
        ];
    }

    private function submissionStatus(FlowSubmission $submission): string
    {
        return $submission->status ?? (($submission->submission_data['status'] ?? null) ?: 'answered');
    }

    private function runStatus(array $answers, array $attempts): string
    {
        if (!empty($answers)) {
            return !empty($attempts) ? 'answered_with_retries' : 'answered';
        }

        $maxRetries = collect($attempts)->contains(fn ($attempt) => $attempt['status'] === 'max_retries_exceeded');

        return $maxRetries ? 'max_retries_exceeded' : 'invalid';
    }

    private function formatContact($contact): ?array
    {
        if (!$contact) {
            return null;
        }

        return [
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'phone' => $contact->formatted_phone_number ?? $contact->phone,
            'email' => $contact->email,
            'chat_url' => $contact->uuid ? '/chats/' . $contact->uuid : null,
        ];
    }
}
