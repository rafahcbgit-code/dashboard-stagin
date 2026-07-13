<?php

namespace App\Services;

use App\Helpers\Email;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Auth;
use DB;
use Str;
use Validator;

class TeamService
{
    public function invite(object $request)
    {
        if ($request->boolean('create_password')) {
            return $this->createMemberWithPassword($request);
        }

        //check if invite already exists
        $invite = TeamInvite::where('email', $request->email)->first();

        if(!$invite){     
            $inviteCode = md5(Carbon::now()->timestamp . session()->get('current_organization') . Str::random(32)); 
            $invite = TeamInvite::create([
                'organization_id' => session()->get('current_organization'),
                'email' => $request->email,
                'code' => $inviteCode,
                'role' => $request->role,
                'invited_by' => auth()->user()->id,
                'expire_at' => now()->addDay(), 
            ]);
        } else  {
            $inviteCode = $invite->code;
            if($invite->expire_at <= now()){
                $inviteCode = md5(Carbon::now()->timestamp . session()->get('current_organization') . Str::random(32));
                $invite->code = $inviteCode;
                $invite->expire_at = now()->addDay();
                $invite->invited_by = auth()->user()->id;
                $invite->save();
            }
        }

        //Send invite email
        $inviter = User::where('id', auth()->user()->id)->first();
        Email::sendInvite('Invite', $request->email, $inviter, url('/invite/' . $inviteCode));

        return $invite;
    }

    private function createMemberWithPassword(object $request)
    {
        return DB::transaction(function () use ($request) {
            $organizationId = session()->get('current_organization');
            $email = strtolower(trim($request->email));
            $user = User::withTrashed()->where('email', $email)->first();

            if (!$user) {
                $name = trim(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));

                $user = User::create([
                    'first_name' => $name !== '' ? $name : $email,
                    'last_name' => null,
                    'email' => $email,
                    'password' => bcrypt($request->password),
                    'role' => 'user',
                    'code_verified' => 1,
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

        if(!$invite){
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('This invite code has expired!')
                ]
            );
        }

        try{
            $user = User::where('email', $request->email)->first();

            if(!$user){
                $user = User::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'password' => bcrypt($request->password),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                //Send new user registration email
                Email::send('Registration', $user);
            }

            $team = Team::updateOrCreate(
                [
                    'organization_id' => $invite->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invite->role,
                    'status' => 'active',
                    'created_by' => $invite->invited_by,
                    'updated_at' => now()
                ]
            );

            $invite->delete();

            Auth::guard('user')->loginUsingId($user->id);

            session()->put('current_organization', $invite->organization_id);
        } catch (\Exception $e) {
            Log::error('Exception: ' . $e->getMessage());
            //dd($e->getMessage());
            //return response()->view('errors.custom', [], 500); // Customize the error response as needed
        }
    }

    public function update(object $request, $uuid)
    {
        Team::where('uuid', $uuid)
            ->where('role', '!=', 'owner')
            ->update([
                'role' => $request->role,
                'updated_at' => now()
            ]);
    }

    public function destroy($uuid)
    {
        $team = Team::where('uuid', $uuid)->first();

        if($team->role === 'owner'){
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('You can\'t delete the main admin account!')
                ]
            );
        } else {
            Team::where('uuid', $uuid)->where('role', '!=', 'owner')->delete();

            return Redirect::back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Row deleted successfully!')
                ]
            );
        }
    }
};
