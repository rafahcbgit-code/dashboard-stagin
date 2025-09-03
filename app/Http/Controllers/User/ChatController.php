<?php

namespace App\Http\Controllers\User;

use Redirect;
use App\Models\Chat;
use Inertia\Inertia;
use App\Models\Label;
use App\Models\Contact;
use App\Models\AutoReply;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Services\ChatService;
use App\Services\WhatsappService;
use App\Http\Controllers\Controller as BaseController;

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

        return Redirect::back();
    }

    public function sendMessage(Request $request)
    {
        return $this->chatService()->sendMessage($request);
    }

    public function sendTemplateMessage(Request $request, $uuid)
    {
        $res = $this->chatService()->sendTemplateMessage($request, $uuid);

        return Redirect::back()->with(
            'status', [
                'type' => $res->success === true ? 'success' : 'error', 
                'message' => $res->success === true ? __('Message sent successfully!') : $res->message,
                'res' => $res
            ]
        );
    }

    public function deleteChats($uuid)
    {
        $this->chatService()->clearContactChat($uuid);

        return Redirect::back()->with(
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
}