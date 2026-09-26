<?php

namespace App\Http\Controllers\User;

use App\Models\Addon;
use App\Models\NotificationLog;
use App\Services\ManagedNotifications\NotificationCatalog;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends BaseController
{
    public function index(Request $request, NotificationCatalog $catalog)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $notifications = NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog))
            ->orderBy('sent_at', 'desc')
            ->paginate(20);

        return Inertia::render('User/Notification/Index', [
            'title' => __('Notifications'),
            'notifications' => $notifications,
            'modules' => Addon::get(),
        ]);
    }

    public function unreadCount(Request $request, NotificationCatalog $catalog)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $count = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog))
            ->where('clicked', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markClicked(Request $request, $id)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $notification = NotificationLog::where('id', $id)
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($notification) {
            $notification->markAsRead();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false], 404);
    }

    public function markAllRead(Request $request, NotificationCatalog $catalog)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->whereIn('notification_type', $this->visibleTypes($catalog))
            ->where('clicked', false)
            ->update([
                'clicked' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    private function visibleTypes(NotificationCatalog $catalog): array
    {
        return array_merge(['chat_assigned', 'chat_note_added'], array_keys($catalog->all()));
    }
}
