<?php

namespace Modules\Accounting\Support;

use Modules\Items\Entities\Product;

/**
 * مبالغ جدول الاصناف كما ستحفظها الخدمة، لعرضها اثناء الادخال (ItemsGridController::totals):
 * وزن كل قطعة وسطر (WeightPieces)، الكمية المسعّرة، الخصم والمجموع (DocumentLineTotals)،
 * ومجاميع المستند، وتنبيهات السطر (الوزن الفعلي فارغ، فرق الوزن). لا يحفظ شيئاً.
 *
 * $extraLines: بنود خارج الجدول تدخل مجاميع المستند (بنود الخدمات في طلبية البيع وفاتورتها):
 * كمية × سعر مع خصم السطر، بلا وزن ولا قطع. اختيارية؛ المستند بلا خدمات لا يرسلها.
 */
final class ItemsGridTotals
{
    /**
     * @param  array<int, array<string, mixed>>  $items  صفوف الجدول بمفتاح كل صف (key) وقطعه
     * @param  array<int, mixed>  $extraLines  بنود الكمية خارج الجدول (الخدمات) بمفتاح كل بند (key)
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, float>, extra_lines?: array<int, array<string, mixed>>}
     */
    public static function compute(array $items, float $documentDiscount = 0, float $taxRate = 0, array $extraLines = []): array
    {
        $items = array_values(array_filter($items, fn ($item): bool => is_array($item) && filled($item['product_id'] ?? null)));
        $products = Product::query()
            ->whereIn('id', array_map(fn (array $item): int => (int) $item['product_id'], $items))
            ->get(['id', 'pricing_type', 'main_product_id', 'length', 'weight_per_meter', 'barcode'])
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']);
            $isWeight = $product?->pricing_type === 'weight';
            $weight = WeightPieces::line($item, $product);
            $actual = $weight['actual_weight'] ?? 0.0;
            $factor = DocumentLineTotals::decimal($item['weight_factor'] ?? 0);

            $quantity = $weight['pricing_weight'] ?? DocumentLineTotals::decimal($item['quantity'] ?? 1, 1);

            // المرتجع وحده له معامل وزن: كغم مفوترة لكل كغم نظري من سطر الفاتورة.
            if ($isWeight && ! ($actual > 0) && $factor > 0) {
                $quantity = round($weight['total_weight'] * $factor, 4);
            }

            $amounts = DocumentLineTotals::line(
                max($quantity, 0),
                max(DocumentLineTotals::decimal($item['unit_price'] ?? 0), 0),
                (string) ($item['discount_mode'] ?? 'rate'),
                DocumentLineTotals::decimal($item['discount_rate'] ?? 0),
                DocumentLineTotals::decimal($item['discount_amount'] ?? 0),
            );

            $notices = [];

            if ($isWeight && ! ($actual > 0) && ! ($factor > 0)) {
                $notices[] = ['type' => 'actual_weight_empty', 'message' => __('Actual weight is empty')];
            }

            if ($isWeight && $actual > 0 && WeightPieces::weightVarianceExceeded($actual, $weight['total_weight'])) {
                $percent = WeightPieces::weightVariancePercent($actual, $weight['total_weight']);
                $notices[] = [
                    'type' => 'weight_variance',
                    'message' => __('Weight variance').' '.number_format((float) $percent, 1).'%',
                    'hint' => __('The actual weight differs from the calculated weight by more than the allowed percentage. This does not block saving.'),
                ];
            }

            $lines[] = [
                'key' => (string) ($item['key'] ?? $item['_key'] ?? ''),
                'pricing_type' => $product?->pricing_type ?? 'simple',
                'total_weight' => $isWeight ? round($weight['total_weight'], 4) : 0.0,
                'pricing_quantity' => round(max($quantity, 0), 4),
                ...$amounts,
                'pieces' => array_map(fn ($piece): array => [
                    'key' => (string) (is_array($piece) ? ($piece['key'] ?? $piece['_key'] ?? '') : ''),
                    'total_weight' => is_array($piece) ? WeightPieces::weight($piece) : 0.0,
                ], $isWeight ? array_values($item['pieces'] ?? []) : []),
                'notices' => $notices,
            ];
        }

        $extra = self::extraLines($extraLines);
        $totals = DocumentLineTotals::document([...$lines, ...$extra], $documentDiscount, $taxRate);

        return $extraLines === []
            ? ['lines' => $lines, 'totals' => $totals]
            : ['lines' => $lines, 'totals' => $totals, 'extra_lines' => $extra];
    }

    /**
     * بند كمية خارج الجدول: الكمية × السعر وخصمه، كما تحسبه خدمة المستند لبند الخدمة.
     *
     * @param  array<int, mixed>  $extraLines
     * @return array<int, array<string, mixed>>
     */
    private static function extraLines(array $extraLines): array
    {
        $lines = [];

        foreach ($extraLines as $line) {
            if (! is_array($line) || blank($line['product_id'] ?? null)) {
                continue;
            }

            $lines[] = [
                'key' => (string) ($line['key'] ?? $line['_key'] ?? ''),
                ...DocumentLineTotals::line(
                    max(DocumentLineTotals::decimal($line['quantity'] ?? 1, 1), 0),
                    max(DocumentLineTotals::decimal($line['unit_price'] ?? 0), 0),
                    (string) ($line['discount_mode'] ?? 'rate'),
                    DocumentLineTotals::decimal($line['discount_rate'] ?? 0),
                    DocumentLineTotals::decimal($line['discount_amount'] ?? 0),
                ),
            ];
        }

        return $lines;
    }
}
