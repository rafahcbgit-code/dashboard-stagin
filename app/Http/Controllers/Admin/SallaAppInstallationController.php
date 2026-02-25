<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\SallaAppInstallationResource;
use App\Models\SallaAppInstallation;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Inertia\Inertia;

class SallaAppInstallationController extends BaseController
{
    public function index(Request $request)
    {
        $perPage = (int) ($request->input('per_page') ?? 25);
        $perPage = max(10, min(100, $perPage));

        $query = SallaAppInstallation::query()
            ->with('organization:id,name')
            ->orderByDesc('installation_date')
            ->orderByDesc('id');

        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', (string) $request->input('merchant_id'));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->input('organization_id'));
        }

        if ($request->filled('app_name')) {
            $query->where('app_name', 'like', '%' . $request->input('app_name') . '%');
        }

        // Filter by installation date range
        if ($request->filled('installed_from') || $request->filled('installed_to')) {
            $from = $request->filled('installed_from') ? Carbon::parse($request->input('installed_from'))->startOfMinute() : null;
            $to = $request->filled('installed_to') ? Carbon::parse($request->input('installed_to'))->endOfMinute() : null;

            if ($from && $to) {
                $query->whereBetween('installation_date', [$from, $to]);
            } elseif ($from) {
                $query->where('installation_date', '>=', $from);
            } elseif ($to) {
                $query->where('installation_date', '<=', $to);
            }
        }

        $rows = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Salla/AppInstallations', [
            'title' => 'Salla App Installations',
            'rows' => SallaAppInstallationResource::collection($rows),
            'filters' => $request->only(['merchant_id', 'organization_id', 'app_name', 'per_page', 'installed_from', 'installed_to']),
        ]);
    }
}
