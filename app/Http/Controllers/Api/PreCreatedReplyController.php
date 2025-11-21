<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\PreCreatedReply;
use App\Services\ChatService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PreCreatedReplyController extends Controller
{
    private $chatService;

    /**
     * List all pre-created replies
     * GET /api/v1/pre-created-replies
     */
    public function index(Request $request)
    {
        $organizationId = $request->organization_id;

        $rows = PreCreatedReply::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->get();

        return \App\Http\Resources\PreCreatedReplyResource::collection($rows);
    }

    /**
     * Send a pre-created reply
     * POST /api/v1/chats/{contact_uuid}/messages/pre-created-reply
     */
    public function send(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;
            
            // Initialize ChatService
            $this->chatService = new ChatService($organizationId);

            // Validation
            $validator = Validator::make($request->all(), [
                'reply_uuid' => 'required|string|exists:pre_created_replies,uuid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check subscription
            if (!SubscriptionService::isSubscriptionActive($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please renew or subscribe to a plan to continue!'
                ], 403);
            }

            // Check WhatsApp connection
            if (!$this->isWhatsAppConnected($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please setup your WhatsApp account first!'
                ], 403);
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

            // Find reply
            $reply = PreCreatedReply::where('uuid', $request->reply_uuid)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$reply) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reply not found'
                ], 404);
            }

            // Construct payload for ChatService
            $payload = $this->constructPayload($reply, $contact->uuid, $userId);

            // Send via ChatService
            $response = $this->chatService->sendMessage((object)$payload);

            // Format response to match MessageController
            if (isset($response->success) && $response->success === true) {
                // Get the sent message from database
                $sentMessage = \App\Models\Chat::with('media')
                    ->where('uuid', $response->data->chat->uuid ?? null)
                    ->first();

                if ($sentMessage) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Message sent successfully',
                        'data' => [
                            'message' => $this->formatMessage($sentMessage)
                        ]
                    ], 200);
                }

                // Fallback
                return response()->json([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => [
                        'message' => [
                            'uuid' => $response->data->chat->uuid ?? null,
                            'type' => 'outbound',
                            'message_type' => $payload['type'] === 'text' ? 'text' : 'interactive',
                            'status' => 'sent',
                            'is_read' => false,
                            'created_at' => now()->toIso8601String(),
                            'wam_id' => $response->data->messages[0]->id ?? null,
                            'content' => [
                                'text' => $payload['message'] ?? ''
                            ]
                        ]
                    ]
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->message ?? 'Failed to send message',
                    'error' => $response->data->error ?? null
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function constructPayload($reply, $contactUuid, $userId)
    {
        $payload = [
            'uuid' => $contactUuid,
            'message' => null,
            'type' => 'text',
            'header' => null,
            'footer' => null,
            'buttons' => [],
            'button_label' => null,
            'user_id' => $userId
        ];

        $content = $reply->content; // Automatically cast to array/object by model

        if ($reply->type === 'text') {
            $payload['type'] = 'text';
            $payload['message'] = $content['body'];
        } elseif ($reply->type === 'quick_reply') {
            $payload['type'] = 'interactive buttons';
            $payload['message'] = $content['body'];
            $payload['buttons'] = array_map(function($btn, $index) {
                return [
                    'id' => "btn_" . time() . "_{$index}",
                    'title' => $btn['text']
                ];
            }, $content['buttons'], array_keys($content['buttons']));
        } elseif ($reply->type === 'header_footer') {
            $payload['type'] = 'interactive buttons';
            $payload['message'] = $content['body'];
            
            if (isset($content['header'])) {
                $payload['header'] = [
                    'type' => 'text',
                    'text' => $content['header']
                ];
            }
            
            if (isset($content['footer'])) {
                $payload['footer'] = $content['footer'];
            }
            
            $payload['buttons'] = array_map(function($btn, $index) {
                return [
                    'id' => "btn_" . time() . "_{$index}",
                    'title' => $btn['text']
                ];
            }, $content['buttons'], array_keys($content['buttons']));
        } elseif ($reply->type === 'link') {
            $payload['type'] = 'interactive call to action url';
            $payload['message'] = $content['body'];
            $payload['buttons'] = [
                'display_text' => $content['link_text'],
                'url' => $content['link_url']
            ];
        }

        return $payload;
    }

    private function isWhatsAppConnected($organizationId)
    {
        $config = \App\Models\Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];
        
        return isset($config['whatsapp']['access_token']) && 
               isset($config['whatsapp']['phone_number_id']) && 
               isset($config['whatsapp']['waba_id']);
    }

    private function formatMessage($message)
    {
        // Helper to format message consistent with MessageController
        // Since this logic is duplicated, ideally it should be in a Trait or Service,
        // but for now we will replicate it to avoid refactoring existing code as per instructions.
        
        $content = [];
        $metadata = json_decode($message->metadata, true);

        if ($message->media) {
            $content['media'] = [
                'url' => $message->media->path,
                'filename' => $message->media->name,
                'type' => $message->media->type,
                'size' => $message->media->size,
            ];
            $content['caption'] = $metadata['image']['caption'] ?? ($metadata['video']['caption'] ?? ($metadata['document']['caption'] ?? null));
        } else {
            $content['text'] = $metadata['text']['body'] ?? ($metadata['interactive']['body']['text'] ?? null);
        }

        return [
            'uuid' => $message->uuid,
            'type' => $message->type,
            'message_type' => $message->media ? explode('/', $message->media->type)[0] : 'text', // Simplified
            'status' => $message->status,
            'is_read' => $message->is_read,
            'created_at' => $message->created_at->toIso8601String(),
            'wam_id' => $message->wam_id,
            'content' => $content
        ];
    }
}
