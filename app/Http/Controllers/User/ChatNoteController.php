<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\Api\ChatNoteResource;
use App\Http\Requests\StoreChatNote;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\Team;
use App\Services\ChatNoteService;
use Illuminate\Http\Request;
use Redirect;

class ChatNoteController extends BaseController
{
    public function __construct(ChatNoteService $chatNoteService)
    {
        $this->chatNoteService = $chatNoteService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'contact' => ['required', 'string'],
        ]);

        $contact = $this->findContactForCurrentOrganization($request->contact);
        $this->authorizeContactAccess($contact);

        $notes = $contact->notes()
            ->with('creator')
            ->get();

        return response()->json([
            'notes' => ChatNoteResource::collection($notes)->resolve($request),
        ]);
    }

    public function store(StoreChatNote $request)
    {
        $contact = $this->findContactForCurrentOrganization($request->contact);
        $this->authorizeContactAccess($contact);

        $note = $this->chatNoteService->store($request, null, auth()->id(), $contact);
        $note->load('creator');

        if ($request->expectsJson()) {
            $noteResource = (new ChatNoteResource($note))->resolve($request);

            return response()->json([
                'message' => __('Note added successfully!'),
                'note' => $noteResource,
                'timeline_item' => [[
                    'type' => 'notes',
                    'value' => $noteResource,
                ]],
            ], 201);
        }

        $contact->load(['lastChat', 'lastInboundChat', 'notes']);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Note added successfully!'),
                'contact' => $contact,
            ]
        );
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request, $uuid)
    {
        $validated = $request->validate([
            'content' => ['required_without:notes', 'nullable', 'string', 'max:5000'],
            'notes' => ['required_without:content', 'nullable', 'string', 'max:5000'],
        ]);

        $note = $this->findNoteForCurrentOrganization($uuid);
        $this->authorizeContactAccess($note->contact);

        $content = $validated['content'] ?? $validated['notes'];
        $updatedNote = $this->chatNoteService->update($note->uuid, $content, auth()->id());
        $updatedNote->load('creator');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Note updated successfully!'),
                'note' => (new ChatNoteResource($updatedNote))->resolve($request),
            ]);
        }

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Note updated successfully!'),
            ]
        );
    }

    public function destroy(Request $request, $uuid)
    {
        $note = $this->findNoteForCurrentOrganization($uuid);
        $this->authorizeContactAccess($note->contact);

        $this->chatNoteService->delete($note->uuid, auth()->id());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Note deleted successfully!'),
            ]);
        }

        return Redirect::back()->with(
            'status',
            [
                'type' => 'success', 
                'message' => __('Note deleted successfully!')
            ]
        );
    }

    private function findContactForCurrentOrganization(string $uuid): Contact
    {
        return Contact::query()
            ->where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    private function findNoteForCurrentOrganization(string $uuid): ChatNote
    {
        return ChatNote::query()
            ->with('contact')
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->whereHas('contact', function ($query) {
                $query->where('organization_id', session()->get('current_organization'))
                    ->whereNull('deleted_at');
            })
            ->firstOrFail();
    }

    private function authorizeContactAccess(Contact $contact): void
    {
        $userId = auth()->id();
        $organizationId = session()->get('current_organization');

        $role = Team::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('role');

        if ($role !== 'agent') {
            return;
        }

        $assignedTo = $contact->ticket()->value('assigned_to');

        if ($assignedTo !== null && (int) $assignedTo !== (int) $userId) {
            abort(403, __('This conversation is assigned to another agent'));
        }
    }
}
