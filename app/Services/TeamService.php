<?php

namespace App\Services;

use App\Helpers\Email;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Auth;
use DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Str;

class TeamService
{
    public function invite(object $request)
    {
        if ($request->boolean('create_password')) {
            return $this->createMemberWithPassword($request);
        }

        //check if invite already exists
        $invite = TeamInvite::where('organization_id', session()->get('current_organization'))
            ->where('email', $request->email)
            ->first();

        if (! $invite) {
            $inviteCode = md5(Carbon::now()->timestamp.session()->get('current_organization').Str::random(32));
            $invite = TeamInvite::create([
                'organization_id' => session()->get('current_organization'),
                'email' => $request->email,
                'code' => $inviteCode,
                'role' => $request->role,
                'phone' => $request->phone,
                'invited_by' => auth()->user()->id,
                'expire_at' => now()->addDay(),
            ]);
        } else {
            $inviteCode = $invite->code;
            if ($invite->expire_at <= now()) {
                $inviteCode = md5(Carbon::now()->timestamp.session()->get('current_organization').Str::random(32));
                $invite->code = $inviteCode;
                $invite->expire_at = now()->addDay();
            }

            $invite->invited_by = auth()->user()->id;
            $invite->role = $request->role;
            $invite->phone = $request->phone;
            $invite->save();
        }

        //Send invite email
        $inviter = auth()->user();
        Email::sendInvite('Invite', $request->email, $inviter, url('/invite/'.$inviteCode));

        return $invite;
    }

    private function createMemberWithPassword(object $request)
    {
        return DB::transaction(function () use ($request) {
            $organizationId = session()->get('current_organization');
            $email = strtolower(trim($request->email));
            $user = User::withTrashed()->where('email', $email)->first();

            if (! $user) {
                $name = trim(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));

                $user = User::create([
                    'first_name' => $name !== '' ? $name : $email,
                    'last_name' => null,
                    'email' => $email,
                    'password' => bcrypt($request->password),
                    'role' => 'user',
                    'code_verified' => 1,
                    'phone' => $request->phone,
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            } else {
                if ($user->trashed()) {
                    $user->restore();
                }

                $user->forceFill([
                    'password' => bcrypt($request->password),
                    'code_verified' => 1,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'phone' => $request->phone,
                ])->save();
            }

            $team = Team::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->first();

            if ($team) {
                if ($team->trashed()) {
                    $team->restore();
                }

                $team->update([
                    'role' => $request->role,
                    'status' => 'active',
                    'created_by' => auth()->user()->id,
                ]);
            } else {
                $team = Team::create([
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'role' => $request->role,
                    'status' => 'active',
                    'created_by' => auth()->user()->id,
                ]);
            }

            TeamInvite::where('organization_id', $organizationId)
                ->where('email', $email)
                ->delete();

            return $team;
        });
    }

    public function store(object $request, $inviteCode)
    {
        $invite = TeamInvite::where('code', $inviteCode)
            ->where('expire_at', '>=', now())
            ->first();

        if (! $invite) {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('This invite code has expired!'),
                ]
            );
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (! $user) {
                $user = User::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'password' => bcrypt($request->password),
                    'phone' => $invite->phone,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                //Send new user registration email
                Email::send('Registration', $user);
            }

            $user->update(['phone' => $invite->phone]);

            $team = Team::updateOrCreate(
                [
                    'organization_id' => $invite->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invite->role,
                    'status' => 'active',
                    'created_by' => $invite->invited_by,
                    'updated_at' => now(),
                ]
            );

            $invite->delete();

            Auth::guard('user')->loginUsingId($user->id);

            session()->put('current_organization', $invite->organization_id);
        } catch (\Exception $e) {
            Log::error('Exception: '.$e->getMessage());
            //dd($e->getMessage());
            //return response()->view('errors.custom', [], 500); // Customize the error response as needed
        }
    }

    public function update(object $request, $uuid)
    {
        $organizationId = session()->get('current_organization');
        $team = Team::with('user')
            ->where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->where('role', '!=', 'owner')
            ->firstOrFail();

        $actorRole = Team::where('organization_id', $organizationId)
            ->where('user_id', auth()->id())
            ->whereNull('deleted_at')
            ->value('role');

        $team->user->update(['phone' => $request->phone]);

        if ($actorRole === 'owner' && $request->filled('role')) {
            $team->role = $request->role;
        }

        $team->updated_at = now();
        $team->save();
    }

    public function destroy($uuid)
    {
        $team = Team::where('uuid', $uuid)->first();

        if ($team->role === 'owner') {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('You can\'t delete the main admin account!'),
                ]
            );
        } else {
            Team::where('uuid', $uuid)->where('role', '!=', 'owner')->delete();

            return Redirect::back()->with(
                'status', [
                    'type' => 'success',
                    'message' => __('Row deleted successfully!'),
                ]
            );
        }
    }
}
