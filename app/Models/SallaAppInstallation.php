<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaAppInstallation extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'salla_store_id',
        'organization_id',
        'source_delivery_id',
        'salla_installation_id',
        'app_name',
        'app_description',
        'app_type',
        'store_type',
        'app_scopes',
        'scopes_count',
        'installation_date',
        'uninstallation_date',
        'uninstalled',
        'webhook_created_at',
        'last_seen_at',
        'payload',
    ];

    protected $casts = [
        'app_scopes' => 'array',
        'payload' => 'array',
        'installation_date' => 'datetime',
        'uninstallation_date' => 'datetime',
        'uninstalled' => 'boolean',
        'webhook_created_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(SallaStore::class, 'salla_store_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function delivery()
    {
        return $this->belongsTo(SallaWebhookDelivery::class, 'source_delivery_id');
    }
}

