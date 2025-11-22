<?php

namespace App\Services;

use App\Http\Resources\ChatNoteResource;
use App\Models\ChatLog;
use App\Models\ChatNote;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class ChatNoteService
{
    /**
     * Get all notes with optional search
     */
    public function get(object $request)
    {
        $rows = (new ChatNote)->listAll($request->query('search'));
        return ChatNoteResource::collection($rows);
    }

    /**
     * Get notes for a specific contact with pagination
     * Used by API
     */
    public function getByContact($contactId, $perPage = 20)
    {
        $notes = ChatNote::where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $notes;
    }

    /**
     * Get single note by UUID
     */
    public function getByUuid($uuid = null)
    {
        return ChatNote::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Create or update note
     * 
     * @param object $request
     * @param string|null $uuid - If provided, updates existing note
     * @param int|null $userId - User ID (for API support)
     * @return ChatNote
     */
    public function store(object $request, $uuid = null, $userId = null)
    {
        // Get contact
        $contact = Contact::where('uuid', $request->contact)->first();

        if (!$contact) {
            throw new \Exception('Contact not found');
        }

        // Get user ID (supports both web and API)
        if (!$userId) {
            $userId = auth('sanctum')->id() ?? auth()->id();
        }

        // Create or update note
        $note = $uuid === null 
            ? new ChatNote() 
            : ChatNote::where('uuid', $uuid)->whereNull('deleted_at')->firstOrFail();

        $note->contact_id = $contact->id;
        $note->content = $request->notes ?? $request->content; // Support both field names
        $note->created_by = $note->exists ? $note->created_by : $userId; // Keep original creator
        $note->save();

        // Create chat log entry
        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'notes',
            'entity_id' => $note->id,
            'created_at' => now()
        ]);

        return $note;
    }

    /**
     * Update existing note
     * 
     * @param string $uuid - Note UUID
     * @param string $content - New content
     * @param int|null $userId - User ID (for API support)
     * @return ChatNote
     */
    public function update($uuid, $content, $userId = null)
    {
        $note = ChatNote::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Get user ID
        if (!$userId) {
            $userId = auth('sanctum')->id() ?? auth()->id();
        }

        // Optional: Check if user is the creator or has permission
        // Uncomment if you want to restrict editing
        /*
        if ($note->created_by !== $userId) {
            // Check if user is manager/owner
            $team = \App\Models\Team::where('user_id', $userId)
                ->where('organization_id', $note->contact->organization_id)
                ->first();
            
            if (!$team || !in_array($team->role, ['owner', 'manager'])) {
                throw new \Exception('You do not have permission to edit this note');
            }
        }
        */

        $note->content = $content;
        $note->save();

        // Create chat log entry for update
        ChatLog::insert([
            'contact_id' => $note->contact_id,
            'entity_type' => 'notes',
            'entity_id' => $note->id,
            'created_at' => now()
        ]);

        return $note;
    }

    /**
     * Soft delete note
     * 
     * @param string $uuid - Note UUID
     * @param int|null $userId - User ID (for API support)
     */
    public function delete($uuid, $userId = null)
    {
        $note = ChatNote::where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Get user ID
        if (!$userId) {
            $userId = auth('sanctum')->id() ?? auth()->id();
        }

        // Optional: Check if user is the creator or has permission
        // Uncomment if you want to restrict deletion
        /*
        if ($note->created_by !== $userId) {
            // Check if user is manager/owner
            $team = \App\Models\Team::where('user_id', $userId)
                ->where('organization_id', $note->contact->organization_id)
                ->first();
            
            if (!$team || !in_array($team->role, ['owner', 'manager'])) {
                throw new \Exception('You do not have permission to delete this note');
            }
        }
        */

        $note->deleted_at = date('Y-m-d H:i:s');
        $note->deleted_by = $userId;
        $note->save();

        return true;
    }
}