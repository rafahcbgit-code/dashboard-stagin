<?php

namespace App\Http\Controllers\Admin;

use App\Models\OtpConfig;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller as BaseController;
use App\Services\OtpService;
use Inertia\Inertia;

class OtpConfigController extends BaseController
{

     private $otpService;

    /**
     * OrganizationController constructor.
     *
     * @param UserService $organizationService
     */
    public function __construct()
    {
        $this->otpService = new OtpService();
    }
    /**
     * Display a listing of the resource.
     */
 public function index(Request $request){
    
        return Inertia::render('Admin/Otp/Index', [
            'title' => __('Otp'),
            'allowCreate' => true,
            'rows' => $this->otpService->get($request), 
            'filters' => $request->all()
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
     public function create()
    {
        $res = $this->otpService->getByUuid(NULL);

        return Inertia::render('Admin/Otp/Show', ['title' => __('Add New Otp Config'), 'role' => $res['role'], 'modules' => $res['modules']]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
    $data = $request->validate([
        'template_name' => 'required|string|max:255',
        'uuid'          => 'nullable|string', 
         'access_token'  => 'required|string',
    ]);

     $this->otpService->updateOrCreateConfig($data);

     return redirect()->route('otp-config.index') 
    ->with('success', __('OTP credentials saved successfully.'));
    }

    public function destroy($id)
    {
        $row = OtpConfig::where('id', $id)->firstOrFail();
        $row->delete();

     return redirect()->route('otp-config.index'); 
    }
}
