<?php

namespace App\Services\WooCommerce\Webhooks;

class WooCommerceOrderItemsSummaryFormatter
{
    /**
     * Build a single-line summary for all order items:
     * "Product A* 2 = 10.000 | Product B* 1 = 5.500"
     */
    public function build(array $payload): string
    {
        $items = data_get($payload, 'line_items', []);
        if (!is_array($items) || empty($items)) {
            return '';
        }

        $chunks = [];
        $index = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $quantity = $item['quantity'] ?? 0;
            $price = $item['total'] ?? $item['price'] ?? '';

            if ($name === '') {
                continue;
            }

            $chunks[] = sprintf('%d. %s x %s = %s', $index, $name, $this->scalarToString($quantity), $this->scalarToString($price));
            $index++;
        }

        return implode("\n", $chunks);
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
