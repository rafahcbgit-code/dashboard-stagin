<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationEmailSender;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class NotificationEmailSenderController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Setting/NotificationSenders', [
            'senderAccounts' => NotificationEmailSender::query()
                ->orderBy('email')->get(['id', 'email', 'host', 'port', 'encryption', 'username']),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(array_merge($this->connectionRules(), [
            'email' => ['required', 'email', 'max:191', Rule::unique('notification_email_senders', 'email')],
            'password' => ['required', 'string', 'max:2048'],
        ]));

        NotificationEmailSender::create($data);

        return back()->with('status', ['type' => 'success', 'message' => 'Notification sender added.']);
    }

    public function update(Request $request, NotificationEmailSender $sender)
    {
        $data = $request->validate(array_merge($this->connectionRules(), [
            'password' => ['nullable', 'string', 'max:2048'],
        ]));

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $sender->update($data);

        return back()->with('status', ['type' => 'success', 'message' => 'Notification sender updated.']);
    }

    private function connectionRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.-]+$/', 'not_in:localhost'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl'])],
            'username' => ['required', 'string', 'max:255'],
        ];
    }
}
