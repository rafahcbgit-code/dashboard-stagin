<?php

return [
    'webhook_delivery_url' => env('WOOCOMMERCE_WEBHOOK_DELIVERY_URL', env('APP_URL') . '/woocommerce/webhooks')
];
