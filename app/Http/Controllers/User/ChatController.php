<?php

namespace App\Http\Controllers\User;

use Redirect;
use App\Models\Chat;
use Inertia\Inertia;
use App\Models\Label;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Services\ChatService;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Team;
use App\Models\Template;

class ChatController extends BaseController
{
    private function chatService()
    {
        return new ChatService(session()->get('current_organization'));
    }

    public function index(Request $request, $uuid = null)
    {
        return $this->chatService()->getChatList($request, $uuid, $request->query('search'));
    }

    public function updateChatSortDirection(Request $request)
    {
        $request->session()->put('chat_sort_direction', $request->sort);

        return redirect()->back();
    }

    public function sendMessage(Request $request)
    {
        return $this->chatService()->sendMessage($request);
    }

    /**
     * Mark all inbound chats as read for the current user.
     *
     * This method updates `chats.is_read` to `1` for inbound messages
     * within the current organization that are associated with chat
     * tickets assigned to the authenticated user. It performs a join
     * against `chat_tickets` using `contact_id` to scope updates to
     * only those chats whose ticket `assigned_to` equals the current
     * user's id. The method returns a JSON response containing the
     * number of rows updated.
     *
     * Note: it updates the `chats` table directly via a joined query,
     * and does not check other chat attributes (e.g. `deleted_at`) here.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $userId = auth()->user()->id;

        $team = Team::where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();

        $role = $team?->role;

        $chatQuery = Chat::where('chats.organization_id', $organizationId)
            ->where('chats.is_read', 0);

        if (!in_array($role, ['owner', 'manager'], true)) {
            $chatQuery->join('chat_tickets', 'chats.contact_id', '=', 'chat_tickets.contact_id')
                ->where('chat_tickets.assigned_to', $userId);
        }

        $updated = $chatQuery->update([
            'chats.is_read' => 1
        ]);

        return response()->json([
            'success' => true,
            'message' => __('All messages marked as read'),
            'updated_count' => $updated
        ]);
    }

    public function sendMetaFlowMessage(Request $request, $uuid)
    {
        $request->validate([
            'meta_flow_uuid' => 'required|string',
            'body'           => 'nullable|string|max:1024',
            'cta'            => 'nullable|string|max:20',
        ]);

        $result = $this->chatService()->sendMetaFlowMessage($request, $uuid);

        return response()->json([
            'success' => $result->success ?? false,
            'message' => $result->success ? __('Flow sent successfully!') : ($result->message ?? __('Failed to send flow.')),
        ], $result->success ? 200 : 422);
    }

    public function sendTemplateMessage(Request $request, $uuid)
    {
        if ($request->input('header.parameters.0.selection') === 'upload') {
            $format = strtoupper((string) $request->input('header.format', ''));

            $request->validate([
                'header.parameters.0.value' => $this->templateHeaderMediaValidationRule($format),
            ]);
        }

        $res = $this->chatService()->sendTemplateMessage($request, $uuid);

        return redirect()->back()->with(
            'status', [
                'type' => $res->success === true ? 'success' : 'error', 
                'message' => $res->success === true ? __('Message sent successfully!') : $res->message,
                'res' => $res
            ]
        );
    }


    public function sendTemplateToPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:40',
            'template' => 'required|string',
        ]);

        if ($request->hasFile('header_media')) {
            $format = $this->resolveTemplateHeaderFormatForRequest((string) $request->input('template'));

            $request->validate([
                'header_media' => $this->templateHeaderMediaValidationRule($format, 'nullable'),
            ]);
        }

        $res = $this->chatService()->sendTemplateToPhone($request);

        return response()->json([
            'success' => $res->success === true,
            'message' => $res->success === true ? __('Message sent successfully!') : ($res->message ?? __('Failed to send message.')),
        ], $res->success === true ? 200 : 422);
    }

    private function templateHeaderMediaValidationRule(string $format, string $presence = 'required'): string
    {
        return match (strtoupper($format)) {
            'IMAGE' => "{$presence}|file|mimes:png,jpg,jpeg|max:5120",
            'VIDEO' => "{$presence}|file|mimes:mp4,3gp|max:16384",
            'DOCUMENT' => "{$presence}|file|mimes:pdf,txt,ppt,doc,xls,docx,pptx,xlsx|max:102400",
            default => "{$presence}|file|mimes:png,jpg,jpeg,pdf,txt,ppt,doc,xls,docx,pptx,xlsx,mp4|max:102400",
        };
    }

    private function resolveTemplateHeaderFormatForRequest(string $templateRef): string
    {
        $template = Template::where('organization_id', session()->get('current_organization'))
            ->where(function ($query) use ($templateRef) {
                $query->where('uuid', $templateRef);

                if (ctype_digit($templateRef)) {
                    $query->orWhere('id', (int) $templateRef);
                }
            })
            ->first();

        $metadata = json_decode($template?->metadata ?? '[]', true) ?: [];

        return strtoupper((string) ($metadata['header']['format'] ?? ''));
    }

    /**
     * Send a carousel template message to a specific contact.
     *
     * Expected POST body:
     * {
     *   "template": "template-uuid"
     * }
     *
     * @param  string  $uuid   The contact UUID (from route parameter)
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendCarouselTemplateMessage(Request $request, $uuid)
    {
        $organizationId = session()->get('current_organization');

        // Resolve template UUID → model
        $template = Template::where('uuid', $request->template)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $template) {
            return redirect()->back()->with('status', [
                'type'    => 'error',
                'message' => __('Template not found'),
            ]);
        }

        // Verify it's actually a carousel template
        $metadata = json_decode($template->metadata, true);
        if (($metadata['template_type'] ?? null) !== 'carousel') {
            return redirect()->back()->with('status', [
                'type'    => 'error',
                'message' => __('Invalid template type. Use the standard send method for non-carousel templates.'),
            ]);
        }

        $chatService = new \App\Services\ChatService($organizationId);
        $res = $chatService->sendCarouselTemplateMessage($uuid, $template->id);

        return redirect()->back()->with('status', [
            'type'    => $res->success === true ? 'success' : 'error',
            'message' => $res->success === true ? __('Message sent successfully!') : ($res->message ?? __('Something went wrong.')),
            'res'     => $res,
        ]);
    }

    public function deleteChats($uuid)
    {
        $this->chatService()->clearContactChat($uuid);

        return redirect()->back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Chat cleared successfully!')
            ]
        );
    }

    public function loadMoreMessages(Request $request, $contactId)
    {
        $page = $request->query('page', 1);
        $messages = $this->chatService()->getChatMessages($contactId, $page);
        
        return response()->json($messages);
    }

    public function getLabelsForFilter()
    {
        $organizationId = session()->get('current_organization');
        
        // Get all labels with contact counts
        $labels = Label::where('organization_id', $organizationId)
            ->withCount(['contacts' => function($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                    ->whereNull('deleted_at');
            }])
            ->having('contacts_count', '>', 0) // Only show labels that have contacts
            ->orderBy('title')
            ->get(['id', 'title', 'color']);

        return response()->json($labels);
    }

    public function getTeamMembersForFilter()
    {
        $organizationId = session()->get('current_organization');
        
        // Get all team members with their user info
        $teamMembers = Team::where('organization_id', $organizationId)
            ->join('users', 'teams.user_id', '=', 'users.id')
            ->select('teams.user_id', 'users.first_name', 'teams.role')
            ->orderBy('users.first_name')
            ->get();

        Log::info('Team members for filter:', [
            'organization_id' => $organizationId,
            'count' => $teamMembers->count(),
            'members' => $teamMembers->toArray()
        ]);

        return response()->json($teamMembers);
    }
}
