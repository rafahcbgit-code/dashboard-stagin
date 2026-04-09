<?php

namespace App\Providers;

use App\Services\Zid\Webhooks\ChatServiceTemplateMessageSender;
use App\Services\Zid\Webhooks\ZidWebhookMessageSenderInterface;
use Illuminate\Support\ServiceProvider;

class ZidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ZidWebhookMessageSenderInterface::class, ChatServiceTemplateMessageSender::class);
    }

    public function boot(): void
    {
        //
    }
}
