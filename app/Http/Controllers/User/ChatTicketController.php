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
        $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $contact = Contact::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $team = Team::where('organization_id', $organizationId)
            ->where('user_id', $request->id)
            ->whereNull('deleted_at')
            ->first();

        $user = User::where('id', $request->id)->first();

        if($team && $user){
            ChatTicket::where('contact_id', $contact->id)->update([
                'assigned_to' => $request->id,
                'updated_at' => now(),
            ]);

            $ticketId = ChatTicketLog::insertGetId([
                'contact_id' => $contact->id,
                'description' => 'Conversation was assigned to ' . $user->first_name . ' ' . $user->last_name,
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

            // Send push + email notification to the assigned agent
            try {
                $notificationService = new ApiNotificationService();
                $notificationService->sendAssignmentNotification(
                    $contact->id,
                    $request->id,
                    auth()->id(),
                    $organizationId
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send assignment notification: ' . $e->getMessage());
            }

            try {
                SendAssignmentEmailJob::dispatch($organizationId, $request->id, $contact->id);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to dispatch assignment email: ' . $e->getMessage());
            }

            return Redirect::back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Ticket assigned successfully!')
                ]
            );
        }

        return Redirect::back()
            ->withErrors(['id' => __('Selected agent is not available for this team.')])
            ->with('status', [
                'type' => 'error',
                'message' => __('Selected agent is not available for this team.')
            ]);
    }
}
