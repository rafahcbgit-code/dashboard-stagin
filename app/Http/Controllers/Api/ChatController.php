<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /**
     * Get chat list (conversations)
     * GET /api/v1/chats
     * 
     * Query Parameters:
     * - search: string (search by name or phone)
     * - labels: string (comma-separated label IDs)
     * - ticket_status: string (all, unassigned, open, closed)
     * - sort: string (newest, oldest)
     * - page: integer
     * - per_page: integer (default: 20, max: 50)
     */
    public function index(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:255',
                'labels' => 'nullable|string', // comma-separated IDs
                'ticket_status' => 'nullable|in:all,unassigned,open,closed',
                'sort' => 'nullable|in:newest,oldest',
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
            $searchTerm = $request->input('search');
            $labelFilter = $request->input('labels'); // "1,2,3"
            $ticketStatus = $request->input('ticket_status', 'all'); // all, unassigned, open, closed
            $sort = $request->input('sort', 'newest'); // newest, oldest
            $perPage = $request->input('per_page', 20);

            // Base query - Get contacts with chats
            $query = Contact::with(['labels', 'contactGroups'])
                ->where('contacts.organization_id', $organizationId)
                ->whereNull('contacts.deleted_at')
                ->whereHas('chats', function ($q) {
                    $q->whereNull('deleted_at');
                });

            // Search filter (name or phone)
            if ($searchTerm) {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('contacts.first_name', 'like', "%{$searchTerm}%")
                        ->orWhere('contacts.last_name', 'like', "%{$searchTerm}%")
                        ->orWhere(DB::raw("CONCAT(contacts.first_name, ' ', contacts.last_name)"), 'like', "%{$searchTerm}%")
                        ->orWhere('contacts.phone', 'like', "%{$searchTerm}%");
                });
            }

            // Label filter
            if ($labelFilter) {
                $labelIds = array_filter(explode(',', $labelFilter));

                if (!empty($labelIds)) {
                    $query->whereHas('labels', function ($q) use ($labelIds) {
                        $q->whereIn('labels.id', $labelIds);
                    });
                }
            }

            // Ticket status filter
            if ($ticketStatus && $ticketStatus !== 'all') {
                if ($ticketStatus === 'unassigned') {
                    // Chats where ticket has no assigned agent OR ticket doesn't exist
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->whereNull('chat_tickets.assigned_to');
                } elseif ($ticketStatus === 'open') {
                    // Chats where ticket status is "open"
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->where('chat_tickets.status', 'open');
                } elseif ($ticketStatus === 'closed') {
                    // Chats where ticket status is "closed"
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->where('chat_tickets.status', 'closed');
                }
                
                // Ensure we select only contact columns to avoid conflicts
                $query->select('contacts.*');
            }

            // Sort by last message time
            $sortDirection = $sort === 'oldest' ? 'asc' : 'desc';
            
            $query->orderBy(
                Chat::select('created_at')
                    ->whereColumn('contact_id', 'contacts.id')
                    ->whereNull('deleted_at')
                    ->latest('created_at')
                    ->limit(1),
                $sortDirection
            );

            // Paginate
            $contacts = $query->paginate($perPage);

            // Format response
            $chats = $contacts->map(function ($contact) use ($organizationId) {

                // Get last chat manually (more reliable)
                $lastChat = Chat::with('media')
                    ->where('contact_id', $contact->id)
                    ->whereNull('deleted_at')
                    ->orderBy('created_at', 'desc')
                    ->first();

                // Get unread count
                $unreadCount = Chat::where('contact_id', $contact->id)
                    ->where('type', 'inbound')
                    ->where('is_read', 0)
                    ->whereNull('deleted_at')
                    ->count();

                // Get ticket info
                $ticket = \App\Models\ChatTicket::with('user')
                    ->where('contact_id', $contact->id)
                    ->first();

                // Parse last message
                $lastMessage = $this->parseLastMessage($lastChat);

                return [
                    'contact' => [
                        'uuid' => $contact->uuid,
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'bot_enable' => $contact->bot_enable,
                        'full_name' => trim($contact->first_name . ' ' . $contact->last_name),
                        'phone' => $contact->phone,
                        'email' => $contact->email,
                        'avatar' => $contact->avatar,
                        'is_favorite' => (bool) $contact->is_favorite,
                    ],
                    'last_message' => $lastMessage,
                    'unread_count' => $unreadCount,
                    'ticket' => $ticket ? [
                        'status' => $ticket->status,
                        'assigned_to' => $ticket->assigned_to ? [
                            'id' => $ticket->user->id,
                            'uuid' => $ticket->user->uuid,
                            'first_name' => $ticket->user->first_name,
                            'last_name' => $ticket->user->last_name,
                            'full_name' => $ticket->user->full_name,
                            'email' => $ticket->user->email,
                        ] : null
                    ] : null,
                    'labels' => $contact->labels->map(function ($label) {
                        return [
                            'id' => $label->id,
                            'title' => $label->title,
                            'color' => $label->color,
                        ];
                    }),
                    'updated_at' => $lastChat ? $lastChat->created_at : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'chats' => $chats,
                ],
                'meta' => [
                    'current_page' => $contacts->currentPage(),
                    'last_page' => $contacts->lastPage(),
                    'per_page' => $contacts->perPage(),
                    'total' => $contacts->total(),
                    'from' => $contacts->firstItem(),
                    'to' => $contacts->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch chats',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Parse last message data
     */
    private function parseLastMessage($chat)
    {
        if (!$chat) {
            return null;
        }

        $metadata = json_decode($chat->metadata, true);
        $messageText = null;
        $messageType = $metadata['type'] ?? 'text';

        // Extract message text based on type
        if ($messageType === 'text' && isset($metadata['text']['body'])) {
            $messageText = $metadata['text']['body'];
        } elseif ($messageType === 'image') {
            $messageText = isset($metadata['image']['caption'])
                ? $metadata['image']['caption']
                : '📷 Image';
        } elseif ($messageType === 'video') {
            $messageText = isset($metadata['video']['caption'])
                ? $metadata['video']['caption']
                : '🎥 Video';
        } elseif ($messageType === 'audio') {
            $messageText = '🎵 Audio';
        } elseif ($messageType === 'document') {
            $messageText = isset($metadata['document']['filename'])
                ? '📄 ' . $metadata['document']['filename']
                : '📄 Document';
        } elseif ($messageType === 'sticker') {
            $messageText = '😊 Sticker';
        } elseif ($messageType === 'location') {
            $messageText = '📍 Location';
        } else {
            $messageText = 'Message';
        }

        return [
            'uuid' => $chat->uuid,
            'text' => $messageText,
            'type' => $chat->type, // inbound or outbound
            'message_type' => $messageType, // text, image, video, etc.
            'status' => $chat->status, // sent, delivered, read, failed
            'created_at' => $chat->created_at,
            'is_read' => $chat->is_read,
            'has_media' => $chat->media_id ? true : false,
        ];
    }

    /**
     * Get unread message count for all chats
     * GET /api/v1/chats/unread-count
     */
    public function unreadCount(Request $request)
    {
        try {
            $organizationId = $request->organization_id;

            $totalUnread = Chat::where('organization_id', $organizationId)
                ->where('type', 'inbound')
                ->where('is_read', 0)
                ->whereNull('deleted_at')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'unread_count' => $totalUnread
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch unread count',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages for a specific contact/conversation
     * GET /api/v1/chats/{contact_uuid}/messages
     * 
     * Query Parameters:
     * - page: integer (default: 1)
     * - per_page: integer (default: 50, max: 100)
     * - sort: string (asc = oldest first, desc = newest first, default: desc)
     */
    public function getMessages(Request $request, $contactUuid)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'sort' => 'nullable|in:asc,desc',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $organizationId = $request->organization_id;
            $perPage = $request->input('per_page', 50);
            $sort = $request->input('sort', 'desc'); // desc = newest first

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

            // Get messages with pagination
            $query = Chat::with(['media', 'contact'])
                ->where('contact_id', $contact->id)
                ->whereNull('deleted_at');

            // Sort order
            if ($sort === 'asc') {
                $query->orderBy('created_at', 'asc'); // Oldest first
            } else {
                $query->orderBy('created_at', 'desc'); // Newest first (default)
            }

            $messages = $query->paginate($perPage);

            // Format messages
            $formattedMessages = $messages->map(function ($chat) {
                return $this->formatMessage($chat);
            });

            // Auto mark as read (only unread inbound messages)
            Chat::where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->where('is_read', 0)
                ->whereNull('deleted_at')
                ->update(['is_read' => 1]);

            return response()->json([
                'success' => true,
                'data' => [
                    'contact' => [
                        'uuid' => $contact->uuid,
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'full_name' => trim($contact->first_name . ' ' . $contact->last_name),
                        'phone' => $contact->phone,
                        'email' => $contact->email,
                        'avatar' => $contact->avatar,
                        'bot_enable' => $contact->bot_enable,
                    ],
                    'messages' => $formattedMessages,
                ],
                'meta' => [
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'per_page' => $messages->perPage(),
                    'total' => $messages->total(),
                    'from' => $messages->firstItem(),
                    'to' => $messages->lastItem(),
                    'has_more' => $messages->hasMorePages(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format message for API response
     */
    private function formatMessage($chat)
    {
        $metadata = json_decode($chat->metadata, true);
        $messageType = $metadata['type'] ?? 'text';

        $message = [
            'uuid' => $chat->uuid,
            'type' => $chat->type, // inbound or outbound
            'message_type' => $messageType,
            'status' => $chat->status,
            'is_read' => (bool) $chat->is_read,
            'created_at' => $chat->created_at,
            'wam_id' => $chat->wam_id,
        ];

        // Text message
        if ($messageType === 'text' && isset($metadata['text']['body'])) {
            $message['content'] = [
                'text' => $metadata['text']['body']
            ];
        }

        // Image
        elseif ($messageType === 'image') {
            $message['content'] = [
                'caption' => $metadata['image']['caption'] ?? null,
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Video
        elseif ($messageType === 'video') {
            $message['content'] = [
                'caption' => $metadata['video']['caption'] ?? null,
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Audio
        elseif ($messageType === 'audio') {
            $message['content'] = [
                'media' => $this->getMediaInfo($chat->media),
                'transcript' => $metadata['transcript'] ?? null
            ];
        }

        // Document
        elseif ($messageType === 'document') {
            $message['content'] = [
                'filename' => $metadata['document']['filename'] ?? 'Document',
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Sticker
        elseif ($messageType === 'sticker') {
            $message['content'] = [
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Location
        elseif ($messageType === 'location') {
            $message['content'] = [
                'latitude' => $metadata['location']['latitude'] ?? null,
                'longitude' => $metadata['location']['longitude'] ?? null,
                'name' => $metadata['location']['name'] ?? null,
                'address' => $metadata['location']['address'] ?? null,
            ];
        }

        // Interactive (buttons/list)
        elseif ($messageType === 'interactive') {
            $message['content'] = [
                'header' => $metadata['header'] ?? null,
                'text' => $metadata['text']['body'] ?? $metadata['body']['text'] ?? null,
                'footer' => $metadata['footer']['text'] ?? null,
                'buttons' => $metadata['buttons'] ?? null,
            ];
        }

        // Button reply
        elseif ($messageType === 'button') {
            $message['content'] = [
                'text' => $metadata['button']['text'] ?? null,
                'payload' => $metadata['button']['payload'] ?? null,
            ];
        }

        return $message;
    }

    /**
     * Get media info
     */
    private function getMediaInfo($media)
    {
        if (!$media) {
            return null;
        }

        return [
            'url' => $media->path,
            'filename' => $media->name,
            'type' => $media->type,
            'size' => $media->size,
        ];
    }

    /**
     * Mark messages as read
     * POST /api/v1/chats/{contact_uuid}/mark-as-read
     */
    public function markAsRead(Request $request, $contactUuid)
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

            // Mark all unread messages as read
            $updated = Chat::where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->where('is_read', 0)
                ->whereNull('deleted_at')
                ->update(['is_read' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'Messages marked as read',
                'data' => [
                    'updated_count' => $updated
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark messages as read',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}