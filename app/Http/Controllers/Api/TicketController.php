<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\ChatLog;
use App\Jobs\SendAssignmentEmailJob;
use App\Services\ApiNotificationService;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    /**
     * Update ticket status (open/close)
     * PUT /api/v1/chats/{contact_uuid}/status
     * 
     * Body: { "status": "closed" }  // or "open"
     */
    public function updateStatus(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;

            // Validation
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:open,closed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

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

            // PERMISSION CHECK for agents
            $userRole = Team::where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->value('role');

            if ($userRole === 'agent') {
                $checkTicket = ChatTicket::where('contact_id', $contact->id)->first();
                if ($checkTicket && $checkTicket->assigned_to && $checkTicket->assigned_to !== $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This conversation is assigned to another agent'
                    ], 403);
                }
            }

            return DB::transaction(function () use ($contact, $organizationId, $request) {
            // Lock an existing ticket so status changes and event logs stay together.
            $ticket = ChatTicket::where('contact_id', $contact->id)->lockForUpdate()->first();
            if (!$ticket) {
            $ticket = ChatTicket::firstOrCreate(
                ['contact_id' => $contact->id],
                [
                    'status' => 'open',
                    'organization_id' => $organizationId,
                    'assigned_to' => null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
            }

            $oldStatus = $ticket->status;
            $newStatus = $request->input('status');

            if ($oldStatus === $newStatus) {
                return response()->json(['success' => true, 'message' => 'Ticket status unchanged', 'data' => ['status' => $ticket->status, 'updated_at' => $ticket->updated_at]], 200);
            }

            // Update ticket status
            $ticket->status = $newStatus;
            $ticket->updated_at = now();
            $ticket->save();

            // Log the status change
            $statusDescription = $newStatus == 'closed'
                ? 'opened to closed'
                : 'closed to open';

            $ticketLogId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => 'Conversation was moved from ' . $statusDescription,
                'created_at' => now()
            ]);

            ChatLog::insert([
                'contact_id' => $contact->id,
                'entity_type' => 'ticket',
                'entity_id' => $ticketLogId,
                'created_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ticket status updated successfully',
                'data' => [
                    'status' => $ticket->status,
                    'updated_at' => $ticket->updated_at
                ]
            ], 200);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update ticket status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign ticket to agent
     * PUT /api/v1/chats/{contact_uuid}/assign
     * 
     * Body: { "user_id": 5 }  // User ID to assign, or null to unassign
     */
    public function assignAgent(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $currentUserId = $request->user('sanctum')->id;

            // Validation
            $validator = Validator::make($request->all(), [
                'user_id' => 'nullable|integer|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

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

            // PERMISSION CHECK for agents
            $userRole = Team::where('organization_id', $organizationId)
                ->where('user_id', $currentUserId)
                ->whereNull('deleted_at')
                ->value('role');

            if ($userRole === 'agent') {
                $checkTicket = ChatTicket::where('contact_id', $contact->id)->first();
                if ($checkTicket && $checkTicket->assigned_to && $checkTicket->assigned_to !== $currentUserId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This conversation is assigned to another agent'
                    ], 403);
                }
            }

            $assignToUserId = $request->input('user_id');

            // If assigning to a user, verify they belong to the organization
            if ($assignToUserId) {
                $team = Team::where('organization_id', $organizationId)
                    ->where('user_id', $assignToUserId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$team) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User does not belong to this organization'
                    ], 403);
                }

                $user = User::where('id', $assignToUserId)->first();

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found'
                    ], 404);
                }
            }

            // Find or create ticket
            $ticket = ChatTicket::firstOrCreate(
                ['contact_id' => $contact->id],
                [
                    'status' => 'open',
                    'assigned_to' => null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            // Update assignment
            $ticket->assigned_to = $assignToUserId;
            $ticket->updated_at = now();
            $ticket->save();

            // Log the assignment
            if ($assignToUserId) {
                $user = User::find($assignToUserId);
                $description = 'Conversation was assigned to ' . $user->first_name . ' ' . $user->last_name;
            } else {
                $description = 'Conversation was unassigned';
            }

            $ticketLogId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => $description,
                'created_at' => now()
            ]);

            ChatLog::insert([
                'contact_id' => $contact->id,
                'entity_type' => 'ticket',
                'entity_id' => $ticketLogId,
                'created_at' => now()
            ]);

            // Send push + email notification to the assigned agent
            if ($assignToUserId) {
                try {
                    $notificationService = new ApiNotificationService();
                    $notificationService->sendAssignmentNotification(
                        $contact->id,
                        $assignToUserId,
                        $currentUserId,
                        $organizationId
                    );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send assignment notification: ' . $e->getMessage());
                }

                try {
                    SendAssignmentEmailJob::dispatch($organizationId, $assignToUserId, $contact->id);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to dispatch assignment email: ' . $e->getMessage());
                }
            }

            // Prepare response
            $assignedUser = null;
            if ($ticket->assigned_to) {
                $user = User::find($ticket->assigned_to);
                $assignedUser = [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'email' => $user->email
                ];
            }

            return response()->json([
                'success' => true,
                'message' => $assignToUserId ? 'Ticket assigned successfully' : 'Ticket unassigned successfully',
                'data' => [
                    'assigned_to' => $assignedUser,
                    'updated_at' => $ticket->updated_at
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket details
     * GET /api/v1/chats/{contact_uuid}/ticket
     */
    public function getTicket(Request $request, $contactUuid)
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

            // PERMISSION CHECK for agents
            $currentUserId = $request->user('sanctum')->id;
            $userRole = Team::where('organization_id', $organizationId)
                ->where('user_id', $currentUserId)
                ->whereNull('deleted_at')
                ->value('role');

            if ($userRole === 'agent') {
                $checkTicket = ChatTicket::where('contact_id', $contact->id)->first();
                if ($checkTicket && $checkTicket->assigned_to && $checkTicket->assigned_to !== $currentUserId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This conversation is assigned to another agent'
                    ], 403);
                }
            }

            // Get ticket
            $ticket = ChatTicket::with('user')
                ->where('contact_id', $contact->id)
                ->first();

            if (!$ticket) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'ticket' => null
                    ]
                ], 200);
            }

            // Format response
            $assignedUser = null;
            if ($ticket->assigned_to && $ticket->user) {
                $assignedUser = [
                    'id' => $ticket->user->id,
                    'uuid' => $ticket->user->uuid,
                    'first_name' => $ticket->user->first_name,
                    'last_name' => $ticket->user->last_name,
                    'full_name' => $ticket->user->full_name,
                    'email' => $ticket->user->email
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ticket' => [
                        'id' => $ticket->id,
                        'status' => $ticket->status,
                        'assigned_to' => $assignedUser,
                        'created_at' => $ticket->created_at,
                        'updated_at' => $ticket->updated_at
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available agents/team members for assignment
     * GET /api/v1/agents
     * 
     * Query Parameters:
     * - role: string (optional) - Filter by role: owner, manager, agent
     */
    public function getAgents(Request $request)
    {
        try {
            $organizationId = $request->organization_id;
            $roleFilter = $request->query('role'); // Optional: owner, manager, agent

            // Validation
            if ($roleFilter && !in_array($roleFilter, ['owner', 'manager', 'agent'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid role. Must be: owner, manager, or agent'
                ], 422);
            }

            // Get team members
            $query = Team::with('user')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereNull('deleted_at');

            // Apply role filter if provided
            if ($roleFilter) {
                $query->where('role', $roleFilter);
            }

            $agents = $query->get()->map(function ($team) {
                return [
                    'id' => $team->user->id,
                    'uuid' => $team->user->uuid,
                    'first_name' => $team->user->first_name,
                    'last_name' => $team->user->last_name,
                    'full_name' => $team->user->full_name,
                    'email' => $team->user->email,
                    'phone' => $team->user->phone,
                    'avatar' => $team->user->avatar,
                    'role' => $team->role, // owner, manager, agent
                    'status' => $team->status, // active, inactive
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'agents' => $agents,
                    'total' => $agents->count()
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agents',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
