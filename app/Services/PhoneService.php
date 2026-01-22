<?php

namespace App\Services;

use App\Helpers\BrazilPhoneHelper;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Support\Facades\Log;

class PhoneService
{
    /**
     * Validate and format a phone number
     * 
     * @param string $phoneNumber
     * @return array ['is_valid' => bool, 'formatted' => string, 'error' => string|null, 'type' => string]
     */
    public static function validateAndFormat($phoneNumber)
    {
        // Basic normalization: trim and remove common invisible chars
        $original = $phoneNumber;
        $phoneNumber = trim($phoneNumber);
        $phoneNumber = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], '', $phoneNumber);

        // Convert Arabic-Indic and Extended Arabic-Indic digits to Latin digits
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $extendedIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $phoneNumber = str_replace($arabicIndic, $latin, $phoneNumber);
        $phoneNumber = str_replace($extendedIndic, $latin, $phoneNumber);

        // Normalize fullwidth plus to normal plus
        $phoneNumber = str_replace('\uFF0B', '+', $phoneNumber);

        // Keep only digits and plus sign
        $phoneNumber = preg_replace('/[^\d+]/u', '', $phoneNumber);
        // If multiple + signs collapse and ensure only one at start
        if (substr_count($phoneNumber, '+') > 1) {
            $phoneNumber = preg_replace('/\++/', '+', $phoneNumber);
            $phoneNumber = '+' . ltrim($phoneNumber, '+');
        }

        // Ensure starts with + if it looks like an international number
        if (!str_starts_with($phoneNumber, '+') && preg_match('/^\d{6,}$/', $phoneNumber)) {
            $phoneNumber = '+' . $phoneNumber;
        }

        // Check if it's a Brazilian number
        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            $validation = BrazilPhoneHelper::validateBrazilPhone($phoneNumber);

            if ($validation['is_valid']) {
                $type = BrazilPhoneHelper::getPhoneType($phoneNumber);
                return [
                    'is_valid' => true,
                    'formatted' => $validation['formatted'],
                    'error' => null,
                    'type' => $type
                ];
            } else {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => $validation['error'],
                    'type' => 'invalid'
                ];
            }
        }

        // For non-Brazilian numbers, use libphonenumber
        try {
            $phone = new PhoneNumber($phoneNumber);

            if ($phone->isValid()) {
                return [
                    'is_valid' => true,
                    'formatted' => $phone->formatE164(),
                    'error' => null,
                    'type' => 'international'
                ];
            } else {
                // Log invalid attempt for debugging
                try {

                } catch (\Throwable $__t) {
                }

                // Fallback: accept Omani numbers (+968) that match expected 8-digit national numbers.
                if (preg_match('/^\+968[1-9]\d{7}$/', $phoneNumber)) {
                    try {

                    } catch (\Throwable $__t) {
                    }
                    return [
                        'is_valid' => true,
                        'formatted' => $phoneNumber,
                        'error' => null,
                        'type' => 'oman'
                    ];
                }

                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Invalid phone number format',
                    'type' => 'invalid'
                ];
            }
        } catch (\Exception $e) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Invalid phone number format',
                'type' => 'invalid'
            ];
        }
    }

    /**
     * Format phone number for display
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function formatForDisplay($phoneNumber)
    {
        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            return BrazilPhoneHelper::formatBrazilPhone($phoneNumber);
        }

        try {
            $phone = new PhoneNumber($phoneNumber);
            return $phone->formatInternational();
        } catch (\Exception $e) {
            return $phoneNumber;
        }
    }

    /**
     * Get E164 formatted phone number
     * 
     * @param string $phoneNumber
     * @return string|null
     */
    public static function getE164Format($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);

        if ($validation['is_valid']) {
            return $validation['formatted'];
        }

        return null;
    }

    /**
     * Check if phone number is valid
     * 
     * @param string $phoneNumber
     * @return bool
     */
    public static function isValid($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);
        return $validation['is_valid'];
    }

    /**
     * Get phone number type
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function getType($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);
        return $validation['type'];
    }

    /**
     * Normalize phone number (ensure it starts with +)
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function normalize($phoneNumber)
    {
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
    }
}
