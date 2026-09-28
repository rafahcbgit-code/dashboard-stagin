<?php

namespace App\Http\Controllers\User;

use App\Models\Addon;
use App\Models\NotificationLog;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Services\ManagedNotifications\NotificationRecipientResolver;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends BaseController
{
    public function index(Request $request, NotificationCatalog $catalog, NotificationRecipientResolver $recipients)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $notifications = NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog, $recipients, (int) $organizationId, (int) $userId))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(20, ['id', 'sent_at']);
        $ids = $notifications->getCollection()->pluck('id');
        $rows = $ids->isEmpty() ? collect() : NotificationLog::query()
            ->whereIn('id', $ids)->get()->keyBy('id');
        $notifications->setCollection($notifications->getCollection()
            ->map(fn ($notification) => $rows->get($notification->id))
            ->filter()->values());

        return Inertia::render('User/Notification/Index', [
            'title' => __('Notifications'),
            'notifications' => $notifications,
            'modules' => Addon::get(),
        ]);
    }

    public function unreadCount(Request $request, NotificationCatalog $catalog, NotificationRecipientResolver $recipients)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $count = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog, $recipients, (int) $organizationId, (int) $userId))
            ->where('clicked', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function pendingManaged(Request $request, NotificationCatalog $catalog, NotificationRecipientResolver $recipients)
    {
        $organizationId = (int) session()->get('current_organization');
        $userId = (int) $request->user()->id;
        if (! $recipients->isActiveOwner($organizationId, $userId)) {
            return response()->json(['notification' => null]);
        }

        $notification = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', array_keys($catalog->all()))
            ->where('clicked', false)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->first(['id', 'payload']);

        return response()->json(['notification' => $notification ? [
            'id' => $notification->id,
            'message' => $notification->payload['message'] ?? $notification->notification_type,
        ] : null]);
    }

    public function markClicked(Request $request, $id, NotificationCatalog $catalog, NotificationRecipientResolver $recipients)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $notification = NotificationLog::where('id', $id)
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog, $recipients, (int) $organizationId, (int) $userId))
            ->first();

        if ($notification) {
            $notification->markAsRead();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false], 404);
    }

    public function markAllRead(Request $request, NotificationCatalog $catalog, NotificationRecipientResolver $recipients)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog, $recipients, (int) $organizationId, (int) $userId))
            ->where('clicked', false)
            ->update([
                'clicked' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    private function visibleTypes(NotificationCatalog $catalog, NotificationRecipientResolver $recipients, int $organizationId, int $userId): array
    {
        $legacy = ['chat_assigned', 'chat_note_added'];

        return $recipients->isActiveOwner($organizationId, $userId)
            ? array_merge($legacy, array_keys($catalog->all()))
            : $legacy;
    }
}
