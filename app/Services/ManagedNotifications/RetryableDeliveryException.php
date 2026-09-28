<?php

namespace App\Services\ManagedNotifications;

use RuntimeException;

class RetryableDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
