<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\Log;

class ApiNotificationService
{
    protected $firebaseService;

    public function __construct()
    {
        $this->firebaseService = new FirebaseService();
    }

    /**
     * Send new message notification
     */
    public function sendNewMessageNotification($chat, $organizationId)
    {
        try {

            if ($chat->type !== 'inbound') {
                return;
            }
            $contact = $chat->contact;
            $metadata = json_decode($chat->metadata, true);
            $messageType = $metadata['type'] ?? 'text';

            // Filter unsupported message types for mobile notifications
            $unsupportedTypes = ['reaction', 'order', 'unsupported'];
            if (in_array($messageType, $unsupportedTypes)) {
                return;
            }

            // Get message preview
            $messagePreview = $this->getMessagePreview($messageType, $metadata);

            // Get contact name (Ensure it's not empty)
            $contactName = trim($contact->first_name . ' ' . $contact->last_name);
            if (empty($contactName) || $contactName === ' ') {
                $contactName = $contact->phone ?: 'New Customer';
            }

            // Get all active users in organization (exclude sender if outbound)
            $userIds = $this->getUsersToNotify($organizationId, $contact->id);

            foreach ($userIds as $userId) {
                // Check if user is online (last activity within 2 minutes)
                if ($this->isUserOnline($userId)) {
                    continue; // Skip - Pusher will handle
                }

                // Get user's device tokens
                $deviceTokens = DeviceToken::activeForUser($userId, $organizationId)
                    ->pluck('device_token')
                    ->toArray();

                if (empty($deviceTokens)) {
                    continue;
                }

                // Get unread count for this user
                $unreadCount = $this->getUnreadCountForUser($userId, $organizationId);

                // Prepare notification data
                $data = [
                    'type' => 'new_message',
                    'contact_uuid' => $contact->uuid,
                    'contact_name' => $contactName,
                    'message_uuid' => $chat->uuid,
                    'msg_type' => $messageType,
                    'organization_id' => (string) $organizationId,
                    'unread_count' => (string) $unreadCount,
                    'click_action' => 'OPEN_CHAT',
                    'message' => "New message from {$contactName}",
                ];

                // Send FCM notification
                $result = $this->firebaseService->sendToMultipleDevices(
                    $deviceTokens,
                    $contactName,
                    $messagePreview,
                    $data
                );

                // Log notification (optional)
                $this->logNotification($userId, $organizationId, 'new_message', $data, $result);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send new message notification: ' . $e->getMessage(), [
                'chat_id' => $chat->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send chat assignment notification
     */
    public function sendAssignmentNotification($contactId, $assignedToUserId, $assignedByUserId, $organizationId)
    {
        try {
            $contact = Contact::find($contactId);
            if (!$contact) {
                return;
            }

            $contactName = trim($contact->first_name . ' ' . $contact->last_name);
            if (empty($contactName)) {
                $contactName = $contact->phone;
            }

            // Get assigner name
            $assignedBy = \App\Models\User::find($assignedByUserId);
            $assignerName = $assignedBy ? $assignedBy->first_name . ' ' . $assignedBy->last_name : 'Admin';

            // Get device tokens for assigned agent
            $deviceTokens = DeviceToken::activeForUser($assignedToUserId, $organizationId)
                ->pluck('device_token')
                ->toArray();

            if (empty($deviceTokens)) {
                return;
            }

            // Prepare notification data
            $data = [
                'type' => 'chat_assigned',
                'contact_uuid' => $contact->uuid,
                'contact_name' => $contactName,
                'assigned_by' => $assignerName,
                'organization_id' => (string) $organizationId,
                'click_action' => 'OPEN_CHAT',
                'message' => "New conversation from {$contactName} assigned to you",
            ];

            // Send FCM notification
            $result = $this->firebaseService->sendToMultipleDevices(
                $deviceTokens,
                'New Chat Assigned',
                "$contactName - Assigned by $assignerName",
                $data
            );

            // Log notification (optional)
            $this->logNotification($assignedToUserId, $organizationId, 'chat_assigned', $data, $result);
        } catch (\Exception $e) {
            Log::error('Failed to send assignment notification: ' . $e->getMessage());
        }
    }

    /**
     * Get message preview text based on message type
     */
    private function getMessagePreview($messageType, $metadata)
    {
        switch ($messageType) {
            case 'text':
                return $metadata['text']['body'] ?? 'New message';
            case 'image':
                $caption = $metadata['body']['text'] ?? $metadata['media']['caption'] ?? $metadata['image']['caption'] ?? null;
                return $caption ? $caption : '📷 Photo';
            case 'video':
                $caption = $metadata['body']['text'] ?? $metadata['media']['caption'] ?? $metadata['video']['caption'] ?? null;
                return $caption ? $caption : '🎥 Video';
            case 'audio':
                return '🎵 Audio message';
            case 'document':
                $filename = $metadata['media']['filename'] ?? $metadata['document']['filename'] ?? 'Document';
                return "📄 $filename";
            case 'sticker':
                return '😊 Sticker';
            case 'location':
                return '📍 Location';
            case 'button':
                return $metadata['button']['text'] ?? 'Button response';
            case 'interactive':
                return $metadata['text']['body'] ?? 'Interactive message';
            default:
                return 'New message';
        }
    }

    /**
     * Get users who should receive notification
     */
    private function getUsersToNotify($organizationId, $contactId)
    {
        // 1. Check if the chat is assigned to someone
        $assignment = \App\Models\ChatTicket::where('contact_id', $contactId)->first();

        if ($assignment && $assignment->assigned_to) {
            // Only notify the assigned agent
            return [$assignment->assigned_to];
        }

        // 2. If unassigned, notify all active team members in organization
        $teamMembers = \App\Models\Team::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->toArray();

        return $teamMembers;
    }

    /**
     * Check if user is currently online/active
     */
    private function isUserOnline($userId)
    {
        // Check last API activity within 2 minutes
        $deviceToken = DeviceToken::where('user_id', $userId)
            ->where('is_active', 1)
            ->orderBy('last_used_at', 'desc')
            ->first();

        if (!$deviceToken || !$deviceToken->last_used_at) {
            return false;
        }

        $lastActivity = $deviceToken->last_used_at;
        $minutesSinceLastActivity = now()->diffInMinutes($lastActivity);

        return $minutesSinceLastActivity < 2;
    }

    /**
     * Get unread message count for user
     */
    private function getUnreadCountForUser($userId, $organizationId)
    {
        return Chat::where('organization_id', $organizationId)
            ->where('type', 'inbound')
            ->where('is_read', 0)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Log notification for debugging (optional)
     */
    private function logNotification($userId, $organizationId, $type, $payload, $fcmResponse)
    {
        try {
            NotificationLog::create([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'notification_type' => $type,
                'payload' => $payload,
                'fcm_response' => $fcmResponse,
                'delivered' => $fcmResponse['success'] ?? false,
            ]);
        } catch (\Exception $e) {
            // Silent fail - logging is optional
            Log::debug('Failed to log notification: ' . $e->getMessage());
        }
    }
}
