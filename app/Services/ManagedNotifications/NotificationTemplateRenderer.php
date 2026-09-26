<?php

namespace App\Services\ManagedNotifications;

use InvalidArgumentException;

class NotificationTemplateRenderer
{
    public function validate(string $template, array $allowedVariables): void
    {
        if (mb_strlen($template) > 20000) {
            throw new InvalidArgumentException('A notification template may not exceed 20,000 characters.');
        }

        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $template, $matches);
        $remainder = preg_replace('/\{\{\s*[a-z][a-z0-9_]*\s*\}\}/', '', $template);

        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            throw new InvalidArgumentException('A notification template contains an invalid variable marker.');
        }

        foreach ($matches[1] as $variable) {
            if (! in_array($variable, $allowedVariables, true)) {
                throw new InvalidArgumentException("Variable {{$variable}} is not allowed for this notification type.");
            }
        }
    }

    public function validateValues(array $values, array $allowedVariables): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, $allowedVariables, true) || ! is_scalar($value) || mb_strlen((string) $value) > 500) {
                throw new InvalidArgumentException("Invalid notification variable: {$key}");
            }
        }

        foreach ($allowedVariables as $key) {
            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException("Missing notification variable: {$key}");
            }
        }
    }

    public function render(string $template, array $values, array $allowedVariables): string
    {
        $this->validate($template, $allowedVariables);
        $this->validateValues($values, $allowedVariables);

        return preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/',
            fn (array $match) => (string) $values[$match[1]],
            $template
        );
    }
}
