<?php

namespace App\Services\ChatbotStudio;

class BlueprintSchema
{
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    private static function items(array $item): array
    {
        // Enforce size limits in the compiler; avoid expanding large bounded grammars upstream.
        return ['type' => 'array', 'items' => $item];
    }

    public static function screen(bool $plan = false): array
    {
        $string = ['type' => 'string'];
        $properties = [
            'key' => $string, 'name' => $string,
            'choices' => self::items(self::object(['label' => $string, 'target' => $string])),
            'list_label' => ['type' => ['string', 'null']], 'next' => ['type' => ['string', 'null']],
        ];
        if ($plan) {
            $properties['source_hint'] = ['type' => 'string'];
        } else {
            $properties['messages'] = self::items($string);
            $properties['questions'] = self::items(self::object([
                'text' => $string, 'variable' => $string,
                'input_type' => ['type' => 'string', 'enum' => ['text', 'phone', 'email', 'number', 'date']],
                'retry_message' => $string,
            ]));
            $properties['cta'] = ['anyOf' => [self::object(['label' => $string, 'url' => $string]), ['type' => 'null']]];
        }
        return self::object($properties);
    }

    public static function blueprint(bool $plan = false): array
    {
        return self::object([
            'name' => ['type' => 'string'], 'start' => ['type' => 'string'], 'trigger' => ['type' => 'string'],
            'issues' => self::items(['type' => 'string']), 'screens' => self::items(self::screen($plan)),
        ]);
    }

    public static function batch(): array
    {
        return self::object(['screens' => self::items(self::screen()), 'issues' => self::items(['type' => 'string'])]);
    }
}
