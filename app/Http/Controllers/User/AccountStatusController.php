<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class AccountStatusController extends Controller
{
    public function suspended()
    {
        return Inertia::render('User/Account/Suspended', [
            'title' => __('Account suspended'),
            'supportEmail' => 'info@chatberry.net',
        ]);
    }
}
