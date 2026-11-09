<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendAssignmentEmailJob;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Team;
use App\Services\ApiNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationTicketController extends Controller
{
    public function agents(Request $request)
    {
        $agents = Team::with('user')
            ->where('organization_id', $request->input('organization'))
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->whereNull('deleted_at'))
            ->get()
            ->map(fn ($team) => [
                'id' => $team->user->id,
                'uuid' => $team->user->uuid,
                'first_name' => $team->user->first_name,
                'last_name' => $team->user->last_name,
                'role' => $team->role,
            ])->values();

        return response()->json(['success' => true, 'data' => ['agents' => $agents]]);
    }

    public function assign(Request $request, string $contactUuid)
    {
        // Require an explicit null to unassign; an omitted field must never clear an assignment.
        $data = $request->validate(['user_id' => 'present|nullable|integer|min:1']);
        $organizationId = $request->input('organization');
        $contact = Contact::where('organization_id', $organizationId)
            ->where('uuid', $contactUuid)->whereNull('deleted_at')->first();

        if (!$contact) {
            return response()->json(['success' => false, 'message' => 'Contact not found'], 404);
        }

        $userId = $data['user_id'];
        $member = null;
        if ($userId !== null) {
            $member = Team::with('user')->where('organization_id', $organizationId)
                ->where('user_id', $userId)->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->whereNull('deleted_at'))->first();
            if (!$member) {
                return response()->json(['success' => false, 'message' => 'User is not an active member of this organization'], 422);
            }
        }

        DB::transaction(function () use ($contact, $userId, $member, $organizationId) {
            // Serialize assignment changes for this contact, including initial ticket creation.
            Contact::where('id', $contact->id)->lockForUpdate()->first();
            $ticket = ChatTicket::firstOrCreate(['contact_id' => $contact->id], [
                'organization_id' => $organizationId,
                'status' => 'open',
                'created_at' => now(),
            ]);
            $ticket->assigned_to = $userId;
            $ticket->updated_at = now();
            $ticket->save();

            $logId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => $member
                    ? 'Conversation was assigned to '.$member->user->first_name.' '.$member->user->last_name.' via organization API'
                    : 'Conversation was unassigned via organization API',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ChatLog::insert([
                'contact_id' => $contact->id, 'entity_type' => 'ticket', 'entity_id' => $logId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        if ($userId !== null) {
            try {
                app(ApiNotificationService::class)->sendAssignmentNotification($contact->id, $userId, null, $organizationId);
            } catch (\Exception $e) {
                Log::error('Organization API assignment notification failed', ['message' => $e->getMessage()]);
            }
            try {
                SendAssignmentEmailJob::dispatch($organizationId, $userId, $contact->id);
            } catch (\Exception $e) {
                Log::error('Organization API assignment email failed', ['message' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $userId !== null ? 'Ticket assigned successfully' : 'Ticket unassigned successfully',
            'data' => ['contact_uuid' => $contact->uuid, 'assigned_to' => $userId === null ? null : (int) $userId],
        ]);
    }
}
