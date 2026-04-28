<?php

namespace App\Services;

use App\Helpers\BrazilPhoneHelper;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Support\Facades\Log;

class PhoneService
{
    private static array $validationCache = [];
    private static array $importE164Cache = [];

    /**
     * Validate and format a phone number
     * 
     * @param string $phoneNumber
     * @return array ['is_valid' => bool, 'formatted' => string, 'error' => string|null, 'type' => string]
     */
    public static function validateAndFormat($phoneNumber)
    {
        $cacheKey = (string) $phoneNumber;
        if (array_key_exists($cacheKey, self::$validationCache)) {
            return self::$validationCache[$cacheKey];
        }

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
                return self::$validationCache[$cacheKey] = [
                    'is_valid' => true,
                    'formatted' => $validation['formatted'],
                    'error' => null,
                    'type' => $type
                ];
            } else {
                return self::$validationCache[$cacheKey] = [
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
                return self::$validationCache[$cacheKey] = [
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
                    return self::$validationCache[$cacheKey] = [
                        'is_valid' => true,
                        'formatted' => $phoneNumber,
                        'error' => null,
                        'type' => 'oman'
                    ];
                }

                return self::$validationCache[$cacheKey] = [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Invalid phone number format',
                    'type' => 'invalid'
                ];
            }
        } catch (\Exception $e) {
            return self::$validationCache[$cacheKey] = [
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
     * Get E164 formatted phone number for imports.
     *
     * This keeps normal runtime validation strict, but allows imports to accept
     * numbers that libphonenumber considers possible even when it cannot mark
     * them as fully valid.
     */
    public static function getE164FormatForImport($phoneNumber): ?string
    {
        $cacheKey = (string) $phoneNumber;
        if (array_key_exists($cacheKey, self::$importE164Cache)) {
            return self::$importE164Cache[$cacheKey];
        }

        $validation = self::validateAndFormat($phoneNumber);

        if ($validation['is_valid']) {
            return self::$importE164Cache[$cacheKey] = $validation['formatted'];
        }

        $normalized = self::normalizePhoneInput($phoneNumber);

        if ($normalized === '') {
            return self::$importE164Cache[$cacheKey] = null;
        }

        try {
            $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
            $phone = $phoneUtil->parse($normalized, null);

            if (! $phoneUtil->isPossibleNumber($phone)) {
                return self::$importE164Cache[$cacheKey] = null;
            }

            return self::$importE164Cache[$cacheKey] = $phoneUtil->format($phone, \libphonenumber\PhoneNumberFormat::E164);
        } catch (\Throwable $e) {
            return self::$importE164Cache[$cacheKey] = null;
        }
    }

    private static function normalizePhoneInput($phoneNumber): string
    {
        $phoneNumber = trim((string) $phoneNumber);
        $phoneNumber = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], '', $phoneNumber);

        $arabicIndic = ['Ù ', 'Ù¡', 'Ù¢', 'Ù£', 'Ù¤', 'Ù¥', 'Ù¦', 'Ù§', 'Ù¨', 'Ù©'];
        $extendedIndic = ['Û°', 'Û±', 'Û²', 'Û³', 'Û´', 'Ûµ', 'Û¶', 'Û·', 'Û¸', 'Û¹'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $phoneNumber = str_replace($arabicIndic, $latin, $phoneNumber);
        $phoneNumber = str_replace($extendedIndic, $latin, $phoneNumber);
        $phoneNumber = str_replace('\uFF0B', '+', $phoneNumber);
        $phoneNumber = preg_replace('/[^\d+]/u', '', $phoneNumber) ?: '';

        if (substr_count($phoneNumber, '+') > 1) {
            $phoneNumber = preg_replace('/\++/', '+', $phoneNumber);
            $phoneNumber = '+' . ltrim($phoneNumber, '+');
        }

        if (str_starts_with($phoneNumber, '00')) {
            $phoneNumber = '+' . substr($phoneNumber, 2);
        }

        if (! str_starts_with($phoneNumber, '+') && preg_match('/^\d{6,}$/', $phoneNumber)) {
            $phoneNumber = '+' . $phoneNumber;
        }

        return $phoneNumber;
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
