<?php

namespace App\Services\Templates;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TemplateAccessService
{
    public function capabilities(?User $user, int $organizationId): array
    {
        $role = $this->role($user, $organizationId);
        $canManage = $user !== null && $role !== 'agent';

        return [
            'view' => $user !== null,
            'create' => $canManage,
            'copy' => $canManage,
            'update' => $canManage,
            'delete' => $canManage,
            'sync' => $canManage,
            'appeal' => $canManage,
        ];
    }

    public function authorize(string $ability, ?User $user, int $organizationId): void
    {
        abort_unless(
            (bool) ($this->capabilities($user, $organizationId)[$ability] ?? false),
            403,
            __('You do not have permission to perform this action.')
        );
    }

    private function role(?User $user, int $organizationId): ?string
    {
        if (! $user) {
            return null;
        }

        return Cache::remember(
            "template-team-role:{$organizationId}:{$user->id}",
            now()->addMinutes(5),
            fn () => Team::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->value('role')
        );
    }
}
