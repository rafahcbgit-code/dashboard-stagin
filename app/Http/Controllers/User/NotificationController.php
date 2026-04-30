<?php

namespace App\Http\Controllers\User;

use App\Models\Addon;
use App\Models\NotificationLog;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends BaseController
{
    public function index(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $notifications = NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('notification_type', 'chat_assigned')
            ->orderBy('sent_at', 'desc')
            ->paginate(20);

        return Inertia::render('User/Notification/Index', [
            'title' => __('Notifications'),
            'notifications' => $notifications,
            'modules' => Addon::get(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        $count = NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('notification_type', 'chat_assigned')
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

    public function markAllRead(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $userId = $request->user()->id;

        NotificationLog::where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('notification_type', 'chat_assigned')
            ->where('clicked', false)
            ->update([
                'clicked' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }
}