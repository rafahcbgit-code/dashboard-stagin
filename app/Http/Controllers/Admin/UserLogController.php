<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserLogController extends BaseController
{
    public function notifications(Request $request)
    {
        return Inertia::render('Admin/UserLog/Notification');
    }

    public function emails(Request $request)
    {
        return Inertia::render('Admin/UserLog/Email');
    }
}
