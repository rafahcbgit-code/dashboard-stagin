<?php

namespace Modules\MetaFlows\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SetupController extends BaseController
{
    /**
     * Handle the admin addon setup form submission for Meta Flows.
     * Saves any settings key/value pairs and updates the addon's is_active flag.
     */
    public function store(Request $request)
    {
        // Persist any arbitrary settings (e.g. API keys, feature flags) sent from the admin form
        if ($request->has('settings') && is_array($request->settings)) {
            foreach ($request->settings as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value]
                );
            }
        }

        // Toggle the addon's global is_active flag when the admin flips the switch
        if ($request->has('is_active')) {
            Addon::where('uuid', $request->uuid)->update(['is_active' => $request->is_active]);
        }

        return redirect('/admin/addons')->with(
            'status', [
                'type'    => 'success',
                'message' => __('Meta Flows settings updated successfully!'),
            ]
        );
    }
}
