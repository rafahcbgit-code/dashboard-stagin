<?php

namespace App\Services;

use App\Models\Chat;
use Carbon\CarbonImmutable;

final class DashboardChartService
{
    /**
     * Return one zero-filled daily series for each supported conversation direction.
     * The aggregate query stays bounded regardless of the selected date range.
     *
     * @return array{period: array<int, string>, inbound: array<int, int>, outbound: array<int, int>}
     */
    public function forOrganization(int $organizationId, int $days): array
    {
        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays($days - 1);
        $period = [];

        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $period[] = $date->format('Y-m-d\TH:i:s.000\Z');
        }

        $counts = Chat::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('type', ['inbound', 'outbound'])
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('DATE(created_at) as date, type, COUNT(*) as aggregate_count')
            ->groupBy('date', 'type')
            ->get()
            ->keyBy(fn ($row) => $row->date.'|'.$row->type);

        $inbound = [];
        $outbound = [];
        foreach ($period as $dateString) {
            $date = substr($dateString, 0, 10);
            $inbound[] = (int) ($counts->get($date.'|inbound')->aggregate_count ?? 0);
            $outbound[] = (int) ($counts->get($date.'|outbound')->aggregate_count ?? 0);
        }

        return compact('period', 'inbound', 'outbound');
    }
}
