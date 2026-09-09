<?php

namespace App\Services;

/**
 * Classifies send failures so deterministic data errors never consume retries.
 */
class CampaignFailureClassifier
{
    /** Meta errors that cannot be corrected by retrying the same payload. */
    private const TERMINAL_META_CODES = ['131009', '132018'];

    public static function isTerminal(mixed $response): bool
    {
        $code = is_object($response)
            ? (string) ($response->error->code ?? '')
            : '';

        return in_array($code, self::TERMINAL_META_CODES, true);
    }
}
