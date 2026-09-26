<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\ManagedNotifications\NotificationCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ManagedNotificationInboxController extends Controller
{
    public function index(Request $request, NotificationCatalog $catalog)
    {
        return Inertia::render('Admin/Notification/Index', [
            'notifications' => NotificationLog::query()
                ->where('user_id', $request->user('admin')->id)
                ->whereIn('notification_type', array_keys($catalog->all()))
                ->orderByDesc('sent_at')->paginate(20),
        ]);
    }

    public function markRead(Request $request, int $id, NotificationCatalog $catalog)
    {
        $notification = NotificationLog::query()
            ->whereKey($id)
            ->where('user_id', $request->user('admin')->id)
            ->whereIn('notification_type', array_keys($catalog->all()))
            ->firstOrFail();
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }
}
