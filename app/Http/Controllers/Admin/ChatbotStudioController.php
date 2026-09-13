<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateChatbotStudioDraft;
use App\Models\ChatbotStudioDraft;
use App\Services\ChatbotStudio\BlueprintCompiler;
use App\Services\ChatbotStudio\BlueprintPublisher;
use App\Services\ChatbotStudio\SourceReader;
use App\Services\ChatbotStudio\StudioAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChatbotStudioController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Admin/ChatbotStudio/Index', [
            'title' => 'Chatbot Studio',
            'ai' => app(StudioAiService::class)->configuration(),
            'drafts' => $this->drafts($request),
        ]);
    }

    public function drafts(Request $request)
    {
        return ChatbotStudioDraft::where('admin_id', $request->user('admin')->id)
            ->leftJoin('organizations', 'organizations.id', '=', 'chatbot_studio_drafts.organization_id')
            ->orderByDesc('chatbot_studio_drafts.updated_at')->limit(50)
            ->get(['chatbot_studio_drafts.id', 'organization_id', 'status', 'chatbot_studio_drafts.updated_at', 'organizations.name as organization_name']);
    }

    public function organizations(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150']);
        return DB::table('organizations')->whereNull('deleted_at')
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) =>
                $q->where('name', 'like', '%'.$search.'%')->orWhere('id', $search)))
            ->orderBy('name')->limit(100)->get(['id', 'name', 'uuid']);
    }

    public function show(Request $request, string $id)
    {
        return $this->draft($request, $id);
    }

    public function generate(Request $request, SourceReader $reader, StudioAiService $ai)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'message' => 'nullable|string|max:20000',
            'url' => 'nullable|string|max:2048',
            'file' => 'nullable|file|max:2048',
            'draft_id' => 'nullable|uuid',
            'version' => 'required_with:draft_id|integer|min:1',
        ]);
        $ai->assertConfigured();
        $draft = isset($data['draft_id']) ? $this->draft($request, $data['draft_id']) : null;
        if ($draft) {
            $this->assertEditable($draft, (int) $data['version']);
            abort_if($draft->organization_id !== (int) $data['organization_id'], 422, 'Organization cannot change within a draft.');
        }
        $message = trim($data['message'] ?? '');
        if (empty($data['url']) && preg_match('~^https://chatbot\.chatberry\.click/[A-Za-z0-9_-]+/?$~', $message)) {
            $data['url'] = $message;
        }
        abort_if($message === '' && empty($data['url']) && !$request->hasFile('file'), 422, 'Add a message, file or simulator URL.');
        $sources = array_filter([$draft?->source]);
        $sourceLabels = $draft?->sources ?? [];
        if (!empty($data['url'])) {
            $sources[] = $reader->fromUrl($data['url']);
            $sourceLabels[] = ['type' => 'url', 'name' => $data['url']];
        }
        if ($request->hasFile('file')) {
            $sources[] = $reader->fromFile($request->file('file'));
            $sourceLabels[] = ['type' => 'file', 'name' => $request->file('file')->getClientOriginalName()];
        }
        if ($message !== '') {
            $sources[] = $message;
        }
        $source = $reader->bounded(implode("\n\n", $sources));
        $message = $message ?: 'Build the complete chatbot from the attached source, preserving its text.';
        $draft = DB::transaction(function () use ($request, $data, $draft, $source, $sourceLabels, $message) {
            if ($draft) {
                $draft = $this->draft($request, $draft->id, true);
                $this->assertEditable($draft, (int) $data['version']);
            } else {
                $draft = new ChatbotStudioDraft(['id' => (string) Str::uuid(), 'organization_id' => (int) $data['organization_id'],
                    'admin_id' => $request->user('admin')->id, 'version' => 0, 'conversation' => []]);
            }
            $history = $draft->conversation;
            $history[] = ['role' => 'user', 'content' => $message];
            $draft->fill(['source' => $source, 'sources' => $sourceLabels, 'conversation' => $history,
                'version' => $draft->version + 1, 'status' => 'generating', 'errors' => null])->save();
            // Database queue insertion participates in this transaction: a failed enqueue leaves no stuck draft.
            GenerateChatbotStudioDraft::dispatch($draft->id, $draft->version);
            return $draft;
        });
        return response()->json($draft, 202);
    }

    public function update(Request $request, string $id, BlueprintCompiler $compiler)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'blueprint' => 'required|array']);
        $blueprint = $compiler->validate($data['blueprint']);
        return DB::transaction(function () use ($request, $id, $data, $blueprint) {
            $draft = $this->draft($request, $id, true);
            $this->assertEditable($draft, (int) $data['version']);
            $draft->update(['blueprint' => $blueprint, 'version' => $draft->version + 1, 'status' => 'draft', 'errors' => null]);
            return $draft;
        });
    }

    public function publish(Request $request, string $id, BlueprintPublisher $publisher)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return $publisher->publish($id, $request->user('admin')->id, $data['version']);
    }

    public function cancel(Request $request, string $id)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        return DB::transaction(function () use ($request, $id, $data) {
            $draft = $this->draft($request, $id, true);
            abort_if($draft->status !== 'generating' || $draft->version !== (int) $data['version'], 409, 'Draft has changed.');
            $draft->update(['status' => $draft->blueprint ? 'draft' : 'failed', 'version' => $draft->version + 1,
                'errors' => ['generation' => ['Generation cancelled.']]]);
            return $draft;
        });
    }

    private function assertEditable(ChatbotStudioDraft $draft, int $version): void
    {
        abort_if(!in_array($draft->status, ['draft', 'failed'], true) || $draft->version !== $version, 409, 'Draft is generating, published or changed. Reload before editing.');
    }

    private function draft(Request $request, string $id, bool $lock = false): ChatbotStudioDraft
    {
        return ChatbotStudioDraft::where('admin_id', $request->user('admin')->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }
}
