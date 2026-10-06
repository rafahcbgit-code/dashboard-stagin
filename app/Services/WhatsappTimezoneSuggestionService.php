<?php

namespace App\Services;

use DateTimeZone;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberToTimeZonesMapper;
use libphonenumber\PhoneNumberUtil;

final class WhatsappTimezoneSuggestionService
{
    /** Suggest a timezone from Meta's international WhatsApp display number. */
    public function fromPhoneNumber(?string $phoneNumber): ?string
    {
        $phoneNumber = trim((string) $phoneNumber);

        if ($phoneNumber === '') {
            return null;
        }

        try {
            $phoneUtil = PhoneNumberUtil::getInstance();
            $parsedNumber = $phoneUtil->parse($phoneNumber, null);

            if (! $phoneUtil->isPossibleNumber($parsedNumber)) {
                return null;
            }

            $knownTimezones = array_fill_keys(DateTimeZone::listIdentifiers(), true);
            $timezones = PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForNumber($parsedNumber);

            foreach ($timezones as $timezone) {
                if ($timezone !== PhoneNumberToTimeZonesMapper::UNKNOWN_TIMEZONE && isset($knownTimezones[$timezone])) {
                    return $timezone;
                }
            }
        } catch (NumberParseException) {
            return null;
        }

        return null;
    }
}
