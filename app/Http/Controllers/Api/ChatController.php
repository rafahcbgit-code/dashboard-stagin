<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ChatLog;
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
     * - ticket_status: string (all, unassigned, open, closed, assigned_to_me)
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
                'labels' => 'nullable|string',
                'ticket_status' => 'nullable|in:all,unassigned,open,closed,assigned_to_me',
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
            $labelFilter = $request->input('labels');
            $ticketStatus = $request->input('ticket_status', 'all');
            $sort = $request->input('sort', 'newest');
            $perPage = $request->input('per_page', 20);

            // Base query - Get contacts with chats
            $query = Contact::with(['labels', 'contactGroups'])
                ->where('contacts.organization_id', $organizationId)
                ->whereNull('contacts.deleted_at')
                ->whereHas('chats', function ($q) {
                    $q->whereNull('deleted_at');
                });

            // Search filter
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
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->whereNull('chat_tickets.assigned_to');
                } elseif ($ticketStatus === 'open') {
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->where('chat_tickets.status', 'open');
                } elseif ($ticketStatus === 'closed') {
                    $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->where('chat_tickets.status', 'closed');
                } elseif ($ticketStatus === 'assigned_to_me') {
                    $currentUserId = $request->user('sanctum')->id;
                    $query->join('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                        ->where('chat_tickets.assigned_to', $currentUserId);
                }
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
            $chats = $contacts->map(function ($contact) {
                $lastChat = Chat::with('media')
                    ->where('contact_id', $contact->id)
                    ->whereNull('deleted_at')
                    ->orderBy('created_at', 'desc')
                    ->first();

                $unreadCount = Chat::where('contact_id', $contact->id)
                    ->where('type', 'inbound')
                    ->where('is_read', 0)
                    ->whereNull('deleted_at')
                    ->count();

                $ticket = \App\Models\ChatTicket::with('user')
                    ->where('contact_id', $contact->id)
                    ->first();

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
            'type' => $chat->type,
            'message_type' => $messageType,
            'status' => $chat->status,
            'created_at' => $chat->created_at,
            'is_read' => $chat->is_read,
            'has_media' => $chat->media_id ? true : false,
        ];
    }

    /**
     * Get unread message count
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
     * - include_types: string (comma-separated: message,note,ticket - default: all)
     */
    public function getMessages(Request $request, $contactUuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'sort' => 'nullable|in:asc,desc',
                'include_types' => 'nullable|string',
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
            $sort = $request->input('sort', 'desc');
            $includeTypes = $request->input('include_types', 'all');

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

            // Parse include_types filter
            $typesToInclude = ['chat', 'notes', 'ticket'];
            
            if ($includeTypes !== 'all') {
                $requestedTypes = array_map('trim', explode(',', $includeTypes));
                $typeMap = [
                    'message' => 'chat',
                    'note' => 'notes',
                    'ticket' => 'ticket'
                ];
                
                $typesToInclude = [];
                foreach ($requestedTypes as $type) {
                    if (isset($typeMap[$type])) {
                        $typesToInclude[] = $typeMap[$type];
                    }
                }
                
                if (empty($typesToInclude)) {
                    $typesToInclude = ['chat', 'notes', 'ticket'];
                }
            }

            // Get chat logs (unified timeline)
            $query = ChatLog::where('contact_id', $contact->id)
                ->whereNull('deleted_at')
                ->whereIn('entity_type', $typesToInclude);

            // Sort order
            if ($sort === 'asc') {
                $query->orderBy('created_at', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $chatLogs = $query->paginate($perPage);

            // Format messages
            $messages = [];
            foreach ($chatLogs as $chatLog) {
                $item = $this->formatTimelineItem($chatLog);
                if ($item) {
                    $messages[] = $item;
                }
            }

            // Auto mark messages as read
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
                    'messages' => $messages,
                ],
                'meta' => [
                    'current_page' => $chatLogs->currentPage(),
                    'last_page' => $chatLogs->lastPage(),
                    'per_page' => $chatLogs->perPage(),
                    'total' => $chatLogs->total(),
                    'from' => $chatLogs->firstItem(),
                    'to' => $chatLogs->lastItem(),
                    'has_more' => $chatLogs->hasMorePages(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Format timeline item based on entity type
     */
    private function formatTimelineItem($chatLog)
    {
        $entityType = $chatLog->entity_type;
        
        try {
            // Type 1: Message
            if ($entityType === 'chat') {
                $chat = $chatLog->relatedEntities;
                if (!$chat) return null;
                
                $formattedMessage = $this->formatMessage($chat);
                $formattedMessage['entity'] = 'message';
                
                return $formattedMessage;
            } 
            
            // Type 2: Note
            elseif ($entityType === 'notes') {
                $note = $chatLog->relatedEntities;
                if (!$note) return null;
                
                $creator = \App\Models\User::find($note->created_by);
                
                return [
                    'entity' => 'note',
                    'uuid' => $note->uuid,
                    'content' => $note->content,
                    'created_by' => $creator ? [
                        'id' => $creator->id,
                        'uuid' => $creator->uuid,
                        'first_name' => $creator->first_name,
                        'last_name' => $creator->last_name,
                        'full_name' => trim($creator->first_name . ' ' . $creator->last_name),
                        'email' => $creator->email,
                    ] : null,
                    'created_at' => $note->created_at,
                    'updated_at' => $note->updated_at,
                ];
            } 
            
            // Type 3: Ticket Event
            elseif ($entityType === 'ticket') {
                $ticketLog = $chatLog->relatedEntities;
                if (!$ticketLog) return null;
                
                return [
                    'entity' => 'ticket_event',
                    'id' => $ticketLog->id,
                    'description' => $ticketLog->description,
                    'created_at' => $ticketLog->created_at,
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            \Log::error('Error formatting timeline item: ' . $e->getMessage(), [
                'entity_type' => $entityType,
                'chat_log_id' => $chatLog->id
            ]);
            return null;
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
            'type' => $chat->type,
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
        // Interactive
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

    /**
     * Get single timeline item by UUID
     * GET /api/v1/timeline-items/{uuid}
     */
    public function getTimelineItem(Request $request, $uuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Try to find as Chat (message)
            $chat = Chat::with(['media', 'contact'])
                ->where('uuid', $uuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if ($chat) {
                if ($chat->contact->organization_id !== $organizationId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized access'
                    ], 403);
                }

                if ($chat->type === 'inbound' && !$chat->is_read) {
                    $chat->update(['is_read' => 1]);
                }

                $messageData = $this->formatMessage($chat);
                $messageData['entity'] = 'message';
                $messageData['contact'] = [
                    'uuid' => $chat->contact->uuid,
                    'first_name' => $chat->contact->first_name,
                    'last_name' => $chat->contact->last_name,
                    'full_name' => trim($chat->contact->first_name . ' ' . $chat->contact->last_name),
                    'phone' => $chat->contact->phone,
                    'email' => $chat->contact->email,
                    'avatar' => $chat->contact->avatar,
                    'bot_enable' => $chat->contact->bot_enable,
                ];

                return response()->json([
                    'success' => true,
                    'data' => $messageData
                ], 200);
            }

            // Try to find as Note
            $note = \App\Models\ChatNote::with('contact', 'creator')
                ->where('uuid', $uuid)
                ->whereNull('deleted_at')
                ->first();

            if ($note) {
                if ($note->contact->organization_id !== $organizationId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized access'
                    ], 403);
                }

                $creator = \App\Models\User::find($note->created_by);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'entity' => 'note',
                        'uuid' => $note->uuid,
                        'content' => $note->content,
                        'created_by' => $creator ? [
                            'id' => $creator->id,
                            'uuid' => $creator->uuid,
                            'first_name' => $creator->first_name,
                            'last_name' => $creator->last_name,
                            'full_name' => trim($creator->first_name . ' ' . $creator->last_name),
                            'email' => $creator->email,
                        ] : null,
                        'created_at' => $note->created_at,
                        'updated_at' => $note->updated_at,
                        'contact' => [
                            'uuid' => $note->contact->uuid,
                            'first_name' => $note->contact->first_name,
                            'last_name' => $note->contact->last_name,
                            'full_name' => trim($note->contact->first_name . ' ' . $note->contact->last_name),
                            'phone' => $note->contact->phone,
                            'email' => $note->contact->email,
                            'avatar' => $note->contact->avatar,
                        ]
                    ]
                ], 200);
            }

            // Not found
            return response()->json([
                'success' => false,
                'message' => 'Timeline item not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch timeline item',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}