<?php

namespace App\Http\Controllers;

use DB;
use App\Helpers\Email;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\SignupRequest;
use App\Http\Requests\StoreUser;
use App\Http\Requests\StoreUserInvite;
use App\Http\Requests\PasswordValidateResetRequest;
use App\Http\Requests\TfaRequest;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Services\UserService;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Template;
use App\Models\PasswordResetToken;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Models\OtpCode;
use App\Services\SocialLoginService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Carbon\Carbon;
use Session;
use Str;
use App\Models\Contact;
use App\Models\OtpConfig;
use App\Services\WhatsappService;
use App\Services\ContactService;
use App\Services\OtpService;
use App\Services\SubscriptionService;

class AuthController extends BaseController
{
    protected $userService;
    protected $role;
    private $whatsappService;
    private $otpService;

    public function __construct($role = 'user')
    {
        $this->userService = new UserService($role);
        $this->otpService = new OtpService();
        $this->role = $role;
    }

    public function showLoginForm(){
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Login', $data);
    }

    public function login(LoginRequest $request){
        $user = User::where('email', $request->email)->where('deleted_at', null)->first();
        $addon = Addon::where('name', 'Google Authenticator')->first()->is_active; 
        $remember = $request->remember;

        if ($user->tfa && $addon == 1) {
            $request->session()->put('tfa', $user->id);
            $request->session()->put('remember', $remember);
      
            return redirect('/tfa');
        }

        return $this->doLogin($request, $user, $remember);
    }

    public function showTfaForm(Request $request)
    {
        if (!$request->session()->has('tfa')) {
            return redirect('/login');
        }

        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login','allow_google_login'];

        $data['companyConfig'] = Setting::whereIn('key', $keys)
            ->pluck('value', 'key')
            ->toArray();

        return Inertia::render('Auth/Tfa', $data);
    }

    public function tfaVerify(TfaRequest $request)
    {
        $userId = $request->session()->get('tfa');
        $remember = $request->session()->get('remember');

        $user = User::find($userId);

        return $this->doLogin($request, $user, $remember);
    }

    private function doLogin(Request $request, $user, $remember)
    {
        $guard = $user->role == 'user' ? 'user' : 'admin';

        if ($request->email || $request->password) {
            Auth::guard($guard)->attempt(['email' => $request->email, 'password' => $request->password], $remember);
        } else {
            Auth::guard($guard)->login($user, $remember);
        }

        //Check number of organizations
        if($guard == 'user'){
            $teams = Team::where('user_id', auth()->user()->id);
            if($teams->count() == 1){
                session()->put('current_organization', $teams->first()->organization_id);
            }
        }

        return redirect($user->role == 'user' ? '/dashboard' : 'admin/dashboard');
    }

    public function handleLogin(StoreUser $request)
    {
        $user = $this->userService->store($request);
        $authService = (new AuthService($user))->authenticateSession($request);

        return redirect('/dashboard');
    }

    public function socialLogin(Request $request, $type){
        if($type === 'google'){
            return SocialLoginService::makeGoogleDriver()->redirect();
        } else if($type === 'facebook'){
            //return Socialite::driver('facebook')->redirect();
            return SocialLoginService::makeFacebookDriver()->redirect();
        }
    }

    public function handleFacebookCallback(Request $request){
        if ($request->has('error')) {
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('There was an error with Facebook login!')
                ]
            );
        }

        try {
            $facebookUser = SocialLoginService::makeFacebookDriver()->fields(['id', 'name', 'first_name', 'last_name', 'email', 'gender', 'verified'])->user();
            $user = User::where('facebook_id', $facebookUser->id)->where('status', '=', '1')->where('deleted_at', null)->first();

            if ($user) {
                if($user->role == 'user'){
                    //Check if user belongs to organization, otherwise set one up
                    $team = Team::where('user_id', $user->id)->first();

                    if(!$team){
                        //Create Organization
                        $organization = $this->createOrganization($user);

                        session()->put('current_organization', $organization->id);
                    }
                }

                $guard = $user->role == 'admin' ? 'admin' : 'user';
                Auth::guard($guard)->login($user);

                return redirect($user->role == 'admin' ? 'admin/dashboard' : '/dashboard');
            } else {
                DB::transaction(function () use ($facebookUser) {
                    // Check if the email exists and handle accordingly
                    $user = User::where('email', $facebookUser->email)->first();

                    if ($user) {
                        // Link the Facebook ID to the existing user
                        $user->facebook_id = $facebookUser->id;
                        $user->save();

                        //Check if user belongs to organization, otherwise set one up
                        $team = Team::where('user_id', $user->id)->first();

                        if(!$team){
                            //Create Organization
                            $organization = $this->createOrganization($user);

                            session()->put('current_organization', $organization->id);
                        }
                    } else {
                        // Extract first name and last name
                        $nameParts = explode(' ', $facebookUser->name);
                        $firstName = $nameParts[0];
                        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

                        // Create User
                        $user = new User();
                        $user->first_name = $firstName;
                        $user->last_name = $lastName;
                        $user->email = $facebookUser->email;
                        $user->facebook_id = $facebookUser->id;
                        $user->password = null;
                        $user->email_verified_at = now();
                        $user->role = 'user';
                        $user->save();
                
                        //Create Organization
                        $organization = $this->createOrganization($user);
                
                        // Send Registration Email
                        Email::send('Registration', $user);

                        if (isset($config->value) && $config->value == '1') {
                            $user->sendEmailVerificationNotification();
                        }

                        session()->put('current_organization', $organization->id);
                    }
                    
                    // Log the user in
                    Auth::guard('user')->login($user, true);
                });
            
                return redirect('dashboard');
            }
        } catch (\Exception $e) {
            // Handle exception, possibly log the error and redirect to an error page
            Log::error('User registration failed: ' . $e->getMessage());
        
            return redirect()->back()->with('error', 'Registration failed, please try again.');
        }
    }

    public function googleCallback(Request $request){
        if ($request->has('error')) {
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('There was an error with Google login!')
                ]
            );
        }

        try {
            $gUser = SocialLoginService::makeGoogleDriver()->user();

            $user = User::where('email', $gUser->email)->where('status', '=', '1')->where('deleted_at', null)->first();

            if ($user) {
                $guard = $user->role == 'admin' ? 'admin' : 'user';
                Auth::guard($guard)->login($user);

                return redirect($user->role == 'admin' ? 'admin/dashboard' : '/dashboard');
            } else {
                //Create User
                $name = explode(" ", $gUser->user['name']);

                $user = new User();
                $user->first_name = $name[0];
                $user->last_name = isset($name[1]) ? $name[1] : NULL;
                $user->email = $gUser->email;
                $user->password = NULL;
                $user->email_verified_at = now();
                $user->role = 'user';
                $user->save();

                $timestamp = now()->format('YmdHis');
                $randomString = Str::random(4);

                //Create Organization
                $organization = Organization::create([
                    'identifier' => $timestamp . $user->id . $randomString,
                    'name' => $name[0] . "'s organization",
                    'created_by' => $user->id
                ]);

                //Create Team
                $team = Team::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'status' => 'active',
                    'created_by' => $user->id
                ]);

                $config = Setting::where('key', 'trial_period')->first();
                $has_trial = isset($config->value) && $config->value > 0 ? true : false;

                //Create Subscription
                Subscription::create([
                    'organization_id' => $organization->id,
                    'status' => $has_trial ? 'trial' : 'active',
                    'plan_id' => null,
                    'start_date' => now(),
                    'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
                ]);

                Email::send('Registration', $user);

                if (isset($config->value) && $config->value == '1') {
                    $user->sendEmailVerificationNotification();
                }

                Auth::guard('user')->login($user, true);
                
                return redirect('dashboard');
            }
        } catch (\Exception $e) {
            // Handle exception, possibly log the error and redirect to an error page
            Log::error('User registration failed: ' . $e->getMessage());
        
            return redirect()->back()->with('error', 'Registration failed, please try again.');
        }
    }

    private function createOrganization($user){
        $timestamp = now()->format('YmdHis');
        $randomString = Str::random(4);

        // Create Organization
        $organization = Organization::create([
            'identifier' => $timestamp . $user->id . $randomString,
            'name' => $user->first_name . "'s organization",
            'created_by' => $user->id
        ]);

        // Create Team
        $team = Team::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => $user->id
        ]);

        $config = Setting::where('key', 'trial_period')->first();
        $has_trial = isset($config->value) && $config->value > 0 ? true : false;

        // Create Subscription
        Subscription::create([
            'organization_id' => $organization->id,
            'status' => $has_trial ? 'trial' : 'active',
            'plan_id' => null,
            'start_date' => now(),
            'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
        ]);

        return $organization;
    }

    public function showRegistrationForm()
    {
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Register', $data);
    }

    public function VerifyAccount()
    {
        return Inertia::render('Auth/VerifyAccount');
    }

    public function handleRegistration(SignupRequest $request)
    {
         try {
        $user = $this->userService->store($request);
        $authService = (new AuthService($user))->authenticateSession($request);
        $config = Setting::where('key', 'verify_email')->first();

        if (isset($config->value) && $config->value == '1') {
            $user->sendEmailVerificationNotification();
        }

        $this->generateOtp($user->phone);

        return redirect('/VerifyAccount');
         } catch (\Exception $e) { 
             \Log::error('Registration Error: '.$e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
        }
    }


// في Controller
public function generateOtp(string $phone = "+963981708294", int $length = 6, int $ttlMinutes = 5)
{
    // 1. استخدام الخدمة لتوليد وحفظ الكود (إزالة التكرار)
    $otpCode = $this->otpService->generateAndSaveOtp($phone, $length, $ttlMinutes);

    // 2. استخدام الخدمة لجلب إعدادات الإرسال (إزالة التكرار)
    $config = $this->otpService->getSenderConfig();

    if ($config) {
        // 3. الإرسال
        $this->sendVerificationCode(
            $phone, 
            $otpCode, 
            $config['organization_id'], 
            $config['template_name'], 
            $config['language']
        );
    }
    // يمكن إضافة منطق للتعامل مع عدم وجود إعدادات إرسال
}


    public function verifyAccountCode(Request $request)
{
    // 1. التحقق من صحة المدخلات
    $request->validate([
        'code' => 'required|string'
    ]);

    $user = auth()->user();

    // 2. جلب آخر كود OTP لهذا المستخدم
    $otp = OtpCode::where('phone', $user->phone)
        ->orderBy('id', 'desc')
        ->first();

    if (!$otp) {
            return back()->withErrors(['general' => 'No verification code found, please resend.']);
        }

    // 3. Check expiration time
    if (now()->greaterThan($otp->expires_at)) {
        return back()->withErrors(['general' => 'The code has expired, please request a new code.']);
    }

    // 4. Check if the code matches
    if ($otp->code !== $request->code) {
        return back()->withErrors(['code' => 'The code is incorrect.']);
    }

    // 6. Delete the code after successful verification
    $otp->delete();

    // 7. Redirect to the dashboard
    return redirect()->route('dashboard')
        ->with('success', 'Your account has been successfully verified.');
}


   public function sendVerificationCode($phone = "+963981708294", $code = "123456", $organization = "29", $template_name = "verify_code_1", $template_lang = "ar")
{
  
    if (!SubscriptionService::isSubscriptionActive($organization)) {
        return response()->json([
            'statusCode' => 403,
            'message' => __('Please renew or subscribe to a plan to continue!'),
        ], 403);
    }

    $phone = $phone;
    if (substr($phone, 0, 1) !== '+') {
        $phone = '+' . $phone;
    }

    $phoneNumber = new \Propaganistas\LaravelPhone\PhoneNumber($phone);
    $phone = $phoneNumber->formatE164();

    $contact = Contact::where('phone', $phone)
        ->where('organization_id', $organization)
        ->whereNull('deleted_at')
        ->first();

    if (!$contact) {
        $contact = Contact::create([
            'organization_id' => $organization,
            'phone' => $phone,
            'created_by' => 0,
        ]);
    }

    $templatePayload = [
        "name" => $template_name,
        "language" => ["code" => $template_lang],
        "components" => [
            [
                "type" => "body",
                "parameters" => [
                    [
                        "type" => "text",
                        "text" => $code
                    ]
                ]
            ],
            [
                "type" => "button",
                "sub_type" => "url",
                "index" => 0,
                "parameters" => [
                    [
                        "type" => "text",
                        "text" => $code
                    ]
                ]
            ]
        ]
    ];

    $this->initializeWhatsappService($organization);

    $response = $this->whatsappService->sendTemplateMessage(
        $contact->uuid,
        $templatePayload,
        0
    );

    return response()->json([
        "statusCode" => 200,
        "message" => "Verification code sent successfully",
        "data" => $response
    ], 200);
}

private function initializeWhatsappService($organizationId)
    {
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
    }

public function resendVerificationCode()
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }
        
        // 1. استخدام الخدمة لتوليد وحفظ الكود (إزالة التكرار)
        // ملاحظة: هنا استخدمنا الطول الافتراضي (6) والوقت الافتراضي (5 دقائق)
        $otpCode = $this->otpService->generateAndSaveOtp($user->phone);

        // 2. استخدام الخدمة لجلب إعدادات الإرسال (إزالة التكرار)
        $config = $this->otpService->getSenderConfig();

        if ($config) {
            // 3. الإرسال
            $this->sendVerificationCode(
                $user->phone, 
                $otpCode, 
                $config['organization_id'], 
                $config['template_name'], 
                $config['language']
            );
        }
    
        return back()->with('success', 'A new code has been sent.');

    } catch (\Exception $e) {

        \Log::error('Resend OTP Error: '.$e->getMessage());

        return back()->withErrors([
            'general' => 'An error occurred while sending the code, please try again later.'
        ]);
    }
}


    public function viewInvite($uuid)
    {
        $invite = TeamInvite::where('code', $uuid)->first();

        if(!$invite){
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('That page does not exist!')
                ]
            );
        } else {
            $data['organization'] = Organization::where('id', $invite->organization_id)->first();
            $data['user'] = User::where('email', $invite->email)->where('role', 'user')->first();
            $data['invite'] = $invite;
            $data['code'] = $uuid;

            $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
            $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            return Inertia::render('Auth/Invite', $data);
        }
    }

    public function invite(StoreUserInvite $request, $inviteCode)
    {
        (new TeamService)->store($request, $inviteCode);

        return Redirect::route('dashboard');
    }

    public function showForgotForm(Request $request)
    {
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Forgot', $data);
    }

    public function createPasswordResetToken(PasswordResetRequest $request)
    {
        (new PasswordResetService)->generateResetLink($request->input('email'));

        return redirect('/forgot-password')->with(
            'status', [
                'type' => 'success', 
                'message' => __('We\'ve sent you a password reset link to your email!')
            ]
        );
    }

    public function showResetForm(Request $request)
    {
        $email = $request->input('email');
        $token = $request->input('token');

        if(!(new PasswordResetService)->verifyResetCode($email, $token)){
            return redirect('/login');
        }

        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Reset', $data);
    }

    public function resetPassword(PasswordValidateResetRequest $request)
    {
        (new PasswordResetService)->resetPassword($request);

        return redirect('/login')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Password reset successful!')
            ]
        );
    }

    public function verifyEmail()
    {
        if(auth()->user()->email_verified_at != NULL){
            return redirect('dashboard');
        } else {
            $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
            $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            return Inertia::render('Auth/VerifyEmail', $data);
        }
    }

    public function sendEmailVerification(Request $request)
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Verification link sent!')
            ]
        );
    }

    public function logout()
    {
        Auth::guard('user')->logout();
        Session::flush();

        return redirect('login');
    }

    public function loginAs($id)
{
    // Only allow if the logged-in user is an admin
    if (!Auth::guard('admin')->check()) {
        abort(403, 'Unauthorized');
    }

    $organization = Organization::findOrFail($id);

    // Find the owner of this organization
    $teamOwner = Team::where('organization_id', $organization->id)
        ->where('role', 'owner')
        ->first();

    if (!$teamOwner) {
        return redirect()->back()->with('error', 'Organization has no owner user.');
    }

    $user = User::find($teamOwner->user_id);

    if (!$user) {
        return redirect()->back()->with('error', 'Owner user not found.');
    }

    // Log out from admin guard
    Auth::guard('admin')->logout();

    // Login as the user of this organization
    Auth::guard('user')->login($user, true);

    // Set current organization session
    session()->put('current_organization', $organization->id);

    return redirect('/dashboard')->with('status', [
        'type' => 'success',
        'message' => "You are now logged in as organization: {$organization->name}"
    ]);
}

    
}