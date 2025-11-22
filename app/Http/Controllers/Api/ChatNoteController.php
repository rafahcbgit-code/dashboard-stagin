<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ChatNote;
use App\Services\ChatNoteService;
use App\Http\Resources\Api\ChatNoteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatNoteController extends Controller
{
    private $chatNoteService;

    public function __construct(ChatNoteService $chatNoteService)
    {
        $this->chatNoteService = $chatNoteService;
    }

    /**
     * Get all notes for a contact
     * GET /api/v1/contacts/{contact_uuid}/notes
     * 
     * Query Parameters:
     * - page: integer (default: 1)
     * - per_page: integer (default: 20, max: 50)
     */
    public function index(Request $request, $contactUuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $organizationId = $request->organization_id;
            $perPage = $request->input('per_page', 20);

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Get notes with pagination
            $notes = $this->chatNoteService->getByContact($contact->id, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'notes' => ChatNoteResource::collection($notes),
                ],
                'meta' => [
                    'current_page' => $notes->currentPage(),
                    'last_page' => $notes->lastPage(),
                    'per_page' => $notes->perPage(),
                    'total' => $notes->total(),
                    'from' => $notes->firstItem(),
                    'to' => $notes->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single note
     * GET /api/v1/contacts/{contact_uuid}/notes/{note_uuid}
     */
    public function show(Request $request, $contactUuid, $noteUuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Find note
            $note = ChatNote::where('uuid', $noteUuid)
                ->where('contact_id', $contact->id)
                ->whereNull('deleted_at')
                ->first();

            if (!$note) {
                return response()->json([
                    'success' => false,
                    'message' => 'Note not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'note' => new ChatNoteResource($note)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new note
     * POST /api/v1/contacts/{contact_uuid}/notes
     * 
     * Body:
     * {
     *     "content": "Note text here"
     * }
     */
    public function store(Request $request, $contactUuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|min:1|max:5000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Create request object for service
            $serviceRequest = (object)[
                'contact' => $contactUuid,
                'content' => $request->content,
            ];

            // Create note
            $note = $this->chatNoteService->store($serviceRequest, null, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Note created successfully',
                'data' => [
                    'note' => new ChatNoteResource($note)
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing note
     * PUT /api/v1/contacts/{contact_uuid}/notes/{note_uuid}
     * 
     * Body:
     * {
     *     "content": "Updated note text"
     * }
     */
    public function update(Request $request, $contactUuid, $noteUuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|min:1|max:5000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Verify note belongs to this contact
            $note = ChatNote::where('uuid', $noteUuid)
                ->where('contact_id', $contact->id)
                ->whereNull('deleted_at')
                ->first();

            if (!$note) {
                return response()->json([
                    'success' => false,
                    'message' => 'Note not found'
                ], 404);
            }

            // Update note
            $updatedNote = $this->chatNoteService->update($noteUuid, $request->content, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Note updated successfully',
                'data' => [
                    'note' => new ChatNoteResource($updatedNote)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete note (soft delete)
     * DELETE /api/v1/contacts/{contact_uuid}/notes/{note_uuid}
     */
    public function destroy(Request $request, $contactUuid, $noteUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Verify note belongs to this contact
            $note = ChatNote::where('uuid', $noteUuid)
                ->where('contact_id', $contact->id)
                ->whereNull('deleted_at')
                ->first();

            if (!$note) {
                return response()->json([
                    'success' => false,
                    'message' => 'Note not found'
                ], 404);
            }

            // Delete note
            $this->chatNoteService->delete($noteUuid, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete note',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}