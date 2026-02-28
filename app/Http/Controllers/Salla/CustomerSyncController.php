<?php

namespace App\Http\Controllers\Salla;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\CustomerSyncRequest;
use App\Services\Salla\SallaCustomerSyncService;
use Inertia\Inertia;
use Throwable;

class CustomerSyncController extends BaseController
{
    public function sync(CustomerSyncRequest $request, SallaCustomerSyncService $service)
    {
        $organizationId = (int) session()->get('current_organization');

        try {
            $summary = $service->sync(
                $organizationId,
                $request->input('date_from'),
                $request->input('date_to'),
                $request->input('tag_name'),
                $request->input('group_name'),
            );

               return redirect()
                ->back()
                ->with('success', sprintf(
                    'Customer sync completed. Fetched: %d, Inserted: %d, Updated: %d, Failed: %d',
                    $summary['total_fetched'] ?? 0,
                    $summary['total_inserted'] ?? 0,
                    $summary['total_updated'] ?? 0,
                    $summary['failed_count'] ?? 0
                ));
        } catch (Throwable $e) {
            dd($e->getMessage(), $e->getTraceAsString());
        }
    }
}
