<?php

namespace App\Support;

class DocumentTotals
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string, items: array<int, array<string, mixed>>}
     */
    public static function calculate(array $items): array
    {
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discountRate = (float) ($item['discount_rate'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineDiscount = round($lineSubtotal * ($discountRate / 100), 2);
            $taxBase = $lineSubtotal - $lineDiscount;
            $lineTax = round($taxBase * ($taxRate / 100), 2);
            $lineTotal = $taxBase + $lineTax;

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;

            $normalized[] = [
                'line_no' => $index + 1,
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'quantity' => self::decimal($quantity, 4),
                'unit' => $item['unit'] ?? 'Pcs',
                'unit_price' => self::decimal($unitPrice),
                'discount_rate' => self::decimal($discountRate),
                'tax_rate' => self::decimal($taxRate),
                'line_subtotal' => self::decimal($lineSubtotal),
                'line_discount' => self::decimal($lineDiscount),
                'line_tax' => self::decimal($lineTax),
                'line_total' => self::decimal($lineTotal),
                'metadata' => $item['metadata'] ?? [],
            ];
        }

        return [
            'subtotal' => self::decimal($subtotal),
            'discount_total' => self::decimal($discountTotal),
            'tax_total' => self::decimal($taxTotal),
            'grand_total' => self::decimal($subtotal - $discountTotal + $taxTotal),
            'items' => $normalized,
        ];
    }

    private static function decimal(float $value, int $scale = 2): string
    {
        return number_format($value, $scale, '.', '');
    }
}

