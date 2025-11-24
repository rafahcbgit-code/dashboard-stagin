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
    //dd($this->otpService->get($request));
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
     public function create(Request $request)
    {
        $res = $this->otpService->getByUuid(NULL);

        return Inertia::render('Admin/Otp/Show', ['title' => __('Add New Otp Config'), 'role' => $res['role'], 'modules' => $res['modules']]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // 1. نقوم بالتحقق أولاً وتخزين البيانات الصحيحة في متغير
    $data = $request->validate([
        'template_name' => 'required|string|max:255',
        'uuid'          => 'nullable|string', // جعلته nullable لأنه قد يكون فارغاً عند الإنشاء الجديد
        'access_token'  => 'required|string',
    ]);

    // 2. نمرر البيانات (array) إلى الخدمة
    $config = $this->otpService->updateOrCreateConfig($data);

        // إعادة التوجيه برسالة نجاح
     return redirect()->route('otp-config.index') 
    ->with('success', __('OTP credentials saved successfully.'));
    }

    /**
     * Display the specified resource.
     */
    public function show(OtpConfig $otpConfig)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(OtpConfig $otpConfig)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, OtpConfig $otpConfig)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
         $row = OtpConfig::where('id', $id)->firstOrFail();
        $row->delete();

    return redirect()->route('otp-config.index');
        
    }
}
