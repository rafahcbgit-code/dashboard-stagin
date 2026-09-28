<?php

namespace App\Services\ManagedNotifications;

use InvalidArgumentException;
use Mews\Purifier\Facades\Purifier;

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

        return $this->replaceVariables($template, $values);
    }

    public function renderEmail(string $template, array $values, array $allowedVariables): array
    {
        $this->validate($template, $allowedVariables);
        $this->validateValues($values, $allowedVariables);

        // Inspect the template, never the variable values, to determine its format.
        $isHtml = $this->isHtml($template);
        $body = $this->replaceVariables($template, $values, $isHtml);
        if (! $isHtml) {
            return ['html' => nl2br(e($body), false), 'text' => $body];
        }

        $html = Purifier::clean($body, 'notification_email');
        return [
            'html' => $html,
            'text' => $this->htmlToText($html),
        ];
    }

    public function normalizeEmailSubject(string $template, array $allowedVariables): string
    {
        $this->validate($template, $allowedVariables);
        if (preg_match('/[\x00-\x1F\x7F]/', html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
            throw new InvalidArgumentException('Email subject is invalid.');
        }

        if ($this->isHtml($template)) {
            $template = preg_replace('/\s+/u', ' ', $this->htmlToText(Purifier::clean($template, 'notification_email')));
        }
        $template = trim($template);
        $this->validateEmailSubject($template);

        return $template;
    }

    public function renderEmailSubject(string $template, array $values, array $allowedVariables): string
    {
        $subject = $this->render($this->normalizeEmailSubject($template, $allowedVariables), $values, $allowedVariables);
        $this->validateEmailSubject($subject);

        return $subject;
    }

    private function validateEmailSubject(string $subject): void
    {
        if ($subject === '' || preg_match('/[\x00-\x1F\x7F]/', $subject) || mb_strlen($subject) > 191) {
            throw new InvalidArgumentException('Rendered email subject is invalid.');
        }
    }

    private function isHtml(string $template): bool
    {
        return (bool) preg_match('/<\/?[a-z][a-z0-9]*(?:\s[^<>]*|\/?)>/i', $template);
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|h[1-6]|tr|li|table|ul|ol)>/i', "\n", $html);
        $text = preg_replace('/<\/(?:td|th)>/i', "\t", $text);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function replaceVariables(string $template, array $values, bool $escapeHtml = false): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/',
            fn (array $match) => $escapeHtml ? e((string) $values[$match[1]]) : (string) $values[$match[1]],
            $template
        );
    }
}
