<?php

namespace App\Services\Salla\Webhooks;

use App\Models\SallaWebhookDelivery;

interface SallaWebhookEventHandlerInterface
{
    public function handle(SallaWebhookDelivery $delivery): void;
}

