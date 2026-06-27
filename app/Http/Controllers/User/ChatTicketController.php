<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Jobs\SendAssignmentEmailJob;
use App\Services\ApiNotificationService;
use App\Services\ChatService;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;

class ChatTicketController extends BaseController
{
    public function index(Request $request, $uuid = null)
    {
        //
    }

    public function update(Request $request, $uuid)
    { 
        $contact = Contact::where('uuid', $uuid)->first();

        ChatTicket::where('contact_id', $contact->id)->update([
            'status' => $request->status,
            'assigned_to' => auth()->user()->id,
            'updated_at' => now(),
        ]);

        $statusDescription = $request->status == 'closed' ? 'opened to closed' : 'closed to open';

        $ticketId = ChatTicketLog::insertGetId([
            'contact_id' => $contact->id,
            'description' => 'Conversation was moved from ' . $statusDescription,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'ticket',
            'entity_id' => $ticketId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Status updated successfully!')
            ]
        );
    }

    public function assign(Request $request, $uuid)
    {
        $organizationId = session()->get('current_organization');
        $requestId = (string) \Illuminate\Support\Str::uuid();

        Log::channel('ticket_assignment')->info('Manual ticket assignment request started', [
            'request_id' => $requestId,
            'contact_uuid' => $uuid,
            'organization_id' => $organizationId,
            'selected_user_id' => $request->input('id'),
            'auth_user_id' => auth()->id(),
            'auth_guard' => auth()->getDefaultDriver(),
            'route' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expects_json' => $request->expectsJson(),
            'is_inertia' => $request->header('X-Inertia') === 'true',
        ]);

        $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $contact = Contact::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        Log::channel('ticket_assignment')->debug('Manual ticket assignment contact resolved', [
            'request_id' => $requestId,
            'contact_id' => $contact->id,
            'contact_uuid' => $contact->uuid,
            'contact_organization_id' => $contact->organization_id,
            'contact_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'contact_phone' => $contact->phone,
        ]);

        $team = Team::where('organization_id', $organizationId)
            ->where('user_id', $request->id)
            ->whereNull('deleted_at')
            ->first();

        $user = User::where('id', $request->id)->first();

        Log::channel('ticket_assignment')->debug('Manual ticket assignment assignee lookup completed', [
            'request_id' => $requestId,
            'selected_user_id' => (int) $request->id,
            'team_found' => (bool) $team,
            'team_id' => $team?->id,
            'team_role' => $team?->role,
            'team_organization_id' => $team?->organization_id,
            'user_found' => (bool) $user,
            'user_id' => $user?->id,
            'user_full_name' => $user?->full_name,
            'user_deleted_at' => $user?->deleted_at,
        ]);

        if($team && $user){
            $ticket = null;
            $ticketBefore = null;
            $ticketAfter = null;

            DB::transaction(function () use ($contact, $organizationId, $request, &$ticket, &$ticketBefore, &$ticketAfter, $requestId) {
                $ticketsForContactBefore = ChatTicket::where('contact_id', $contact->id)
                    ->orderBy('id')
                    ->get(['id', 'organization_id', 'contact_id', 'assigned_to', 'status', 'created_at', 'updated_at'])
                    ->toArray();

                $ticket = ChatTicket::where('contact_id', $contact->id)
                    ->lockForUpdate()
                    ->first();

                $ticketBefore = $ticket ? $ticket->only([
                    'id',
                    'organization_id',
                    'contact_id',
                    'assigned_to',
                    'status',
                    'created_at',
                    'updated_at',
                ]) : null;

                Log::channel('ticket_assignment')->debug('Manual ticket assignment ticket before save', [
                    'request_id' => $requestId,
                    'ticket_found' => (bool) $ticket,
                    'ticket_before' => $ticketBefore,
                    'tickets_for_contact_count' => count($ticketsForContactBefore),
                    'tickets_for_contact_before' => $ticketsForContactBefore,
                ]);

                if (! $ticket) {
                    $ticket = ChatTicket::create([
                        'organization_id' => $organizationId,
                        'contact_id' => $contact->id,
                        'status' => 'open',
                        'assigned_to' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    Log::channel('ticket_assignment')->warning('Manual ticket assignment created missing ticket', [
                        'request_id' => $requestId,
                        'ticket_id' => $ticket->id,
                        'contact_id' => $contact->id,
                        'organization_id' => $organizationId,
                    ]);
                }

                $ticket->assigned_to = (int) $request->id;
                $ticket->updated_at = now();
                $ticket->save();
                $ticket->refresh();

                $syncedDuplicateTicketIds = ChatTicket::where('contact_id', $contact->id)
                    ->where('id', '!=', $ticket->id)
                    ->pluck('id')
                    ->toArray();

                if (! empty($syncedDuplicateTicketIds)) {
                    ChatTicket::whereIn('id', $syncedDuplicateTicketIds)->update([
                        'organization_id' => $organizationId,
                        'assigned_to' => (int) $request->id,
                        'updated_at' => now(),
                    ]);

                    Log::channel('ticket_assignment')->warning('Manual ticket assignment synced duplicate tickets for contact', [
                        'request_id' => $requestId,
                        'primary_ticket_id' => $ticket->id,
                        'synced_duplicate_ticket_ids' => $syncedDuplicateTicketIds,
                        'contact_id' => $contact->id,
                        'assigned_to' => (int) $request->id,
                    ]);
                }

                $ticketAfter = $ticket->only([
                    'id',
                    'organization_id',
                    'contact_id',
                    'assigned_to',
                    'status',
                    'created_at',
                    'updated_at',
                ]);

                Log::channel('ticket_assignment')->info('Manual ticket assignment ticket saved', [
                    'request_id' => $requestId,
                    'ticket_before' => $ticketBefore,
                    'ticket_after' => $ticketAfter,
                    'tickets_for_contact_after' => ChatTicket::where('contact_id', $contact->id)
                        ->orderBy('id')
                        ->get(['id', 'organization_id', 'contact_id', 'assigned_to', 'status', 'created_at', 'updated_at'])
                        ->toArray(),
                    'assignment_changed' => (int) ($ticketBefore['assigned_to'] ?? 0) !== (int) $ticket->assigned_to,
                ]);
            });

            $ticketId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => 'Conversation was assigned to ' . $user->first_name . ' ' . $user->last_name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::channel('ticket_assignment')->debug('Manual ticket assignment ticket log inserted', [
                'request_id' => $requestId,
                'chat_ticket_log_id' => $ticketId,
                'contact_id' => $contact->id,
                'assigned_to' => (int) $request->id,
            ]);

            ChatLog::insert([
                'contact_id' => $contact->id,
                'entity_type' => 'ticket',
                'entity_id' => $ticketId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::channel('ticket_assignment')->debug('Manual ticket assignment chat log inserted', [
                'request_id' => $requestId,
                'chat_ticket_log_id' => $ticketId,
                'contact_id' => $contact->id,
            ]);

            // Send push + email notification to the assigned agent
            try {
                $notificationService = new ApiNotificationService();
                $notificationService->sendAssignmentNotification(
                    $contact->id,
                    $request->id,
                    auth()->id(),
                    $organizationId
                );
                Log::channel('ticket_assignment')->debug('Manual ticket assignment push notification sent', [
                    'request_id' => $requestId,
                    'contact_id' => $contact->id,
                    'assigned_to' => (int) $request->id,
                    'assigned_by' => auth()->id(),
                    'organization_id' => $organizationId,
                ]);
            } catch (\Exception $e) {
                Log::channel('ticket_assignment')->error('Manual ticket assignment push notification failed', [
                    'request_id' => $requestId,
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            try {
                SendAssignmentEmailJob::dispatch($organizationId, $request->id, $contact->id);
                Log::channel('ticket_assignment')->debug('Manual ticket assignment email job dispatched', [
                    'request_id' => $requestId,
                    'contact_id' => $contact->id,
                    'assigned_to' => (int) $request->id,
                    'organization_id' => $organizationId,
                ]);
            } catch (\Exception $e) {
                Log::channel('ticket_assignment')->error('Manual ticket assignment email dispatch failed', [
                    'request_id' => $requestId,
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            Log::channel('ticket_assignment')->info('Manual ticket assignment request finished successfully', [
                'request_id' => $requestId,
                'contact_id' => $contact->id,
                'ticket_id' => $ticket?->id,
                'assigned_to' => (int) $request->id,
                'ticket_after' => $ticketAfter,
            ]);

            return Redirect::back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Ticket assigned successfully!')
                ]
            );
        }

        Log::channel('ticket_assignment')->warning('Manual ticket assignment rejected because assignee is unavailable', [
            'request_id' => $requestId,
            'contact_id' => $contact->id,
            'organization_id' => $organizationId,
            'selected_user_id' => (int) $request->id,
            'team_found' => (bool) $team,
            'user_found' => (bool) $user,
            'team_id' => $team?->id,
            'user_deleted_at' => $user?->deleted_at,
        ]);

        return Redirect::back()
            ->withErrors(['id' => __('Selected agent is not available for this team.')])
            ->with('status', [
                'type' => 'error',
                'message' => __('Selected agent is not available for this team.')
            ]);
    }
}
