<?php

namespace App\Services\Salla\Webhooks;

class PayloadFieldExtractor
{
    /**
     * Flatten a webhook payload into dot-notated field paths for UI mapping.
     *
     * @return array<int, array{path:string, sample:mixed}>
     */
    public function extract(array $payload, int $maxDepth = 6, int $maxFields = 500): array
    {
        $fields = [];
        $this->walk($payload, '', 0, $maxDepth, $maxFields, $fields);

        usort($fields, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $fields;
    }

    /**
     * @param array<int, array{path:string, sample:mixed}> $fields
     */
    private function walk(mixed $value, string $path, int $depth, int $maxDepth, int $maxFields, array &$fields): void
    {
        if (count($fields) >= $maxFields) {
            return;
        }

        if ($depth > $maxDepth) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $key = is_int($key) ? (string) $key : $key;
                $childPath = $path === '' ? $key : $path . '.' . $key;
                $this->walk($child, $childPath, $depth + 1, $maxDepth, $maxFields, $fields);
            }
            return;
        }

        if (is_object($value)) {
            $this->walk((array) $value, $path, $depth, $maxDepth, $maxFields, $fields);
            return;
        }

        if ($path !== '') {
            $fields[] = [
                'path' => $path,
                'sample' => $value,
            ];
        }
    }
}

