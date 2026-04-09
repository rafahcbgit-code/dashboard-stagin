<?php

namespace App\Services\Zid\Webhooks;

use App\Models\ZidWebhookDelivery;

interface ZidWebhookEventHandlerInterface
{
    public function handle(ZidWebhookDelivery $delivery): void;
}
