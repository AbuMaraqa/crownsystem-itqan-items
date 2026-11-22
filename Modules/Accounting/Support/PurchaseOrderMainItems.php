<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Items\Entities\Product;
use Modules\Items\Support\CategorySearch;

/**
 * الصنف الرئيسي واصنافه الفرعية في الطلبية التجميعية، كما في شاشة النظام القديم: رأس فيه
 * الصنف الرئيسي بكوده ووزنه، وتحته جدول اصنافه الفرعية (عدد القطع × طول القطعة × وزن المتر
 * = الوزن الكلي). مصدر واحد لنافذة السحب وتبويب الاصناف (Vue).
 *
 * كل بند في طلبية الشراء يُعرض صنفاً رئيسياً واحداً:
 * - بند الوزن الذي فيه قطع: البند هو الصنف الرئيسي، والقطع اصنافه الفرعية.
 * - بند صنفه فرعي (أُدخل وحده قبل شجرة الاصناف): رأسه صنفه الرئيسي من شجرة الاصناف، او
 *   مجموعته في طلبية الشراء، والبند نفسه صنفه الفرعي الوحيد.
 * - غير ذلك (صنف الكمية): البند وحده بلا اصناف فرعية.
 */
final class PurchaseOrderMainItems
{
    /**
     * الصنف الرئيسي لكل بند واصنافه الفرعية، مفهرساً بمعرّف البند. البنود تُحمَّل مع product
     * و pieces، وبند الطلبية التجميعية مع sourcePurchaseOrderItem لمعرفة مجموعته.
     *
     * @param  iterable<PurchaseOrderItem|ConsolidatedPurchaseOrderItem>  $lines
     * @return array<int, array{name: string, code: string|null, barcode: string|null, line_is_main: bool, is_weight_priced: bool, length: float|null, quantity: float, total_weight: float|null, actual_weight: float|null, sub_items: array<int, array{name: string, barcode: string, count: float, length: float|null, weight_per_meter: float|null, total_weight: float|null}>}>
     */
    public static function for(iterable $lines): array
    {
        $lines = collect($lines);

        $mainProducts = Product::query()
            ->select(['id', 'sku', 'barcode'])
            ->with('translations')
            ->whereIn('id', $lines->map(fn ($line) => $line->product?->main_product_id)->filter()->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $categoryLabels = CategorySearch::labels($lines->map(fn ($line) => self::categoryId($line))->filter()->unique()->values()->all());

        return $lines
            ->mapWithKeys(fn ($line): array => [(int) $line->id => self::mainItemFor($line, $mainProducts, $categoryLabels)])
            ->all();
    }

    /**
     * هل يظهر الصنف الرئيسي في نتائج البحث؟ ومعه ارقام اصنافه الفرعية المطابقة لتمييزها.
     *
     * - النص يطابق اسم الصنف الرئيسي او كوده او باركوده، او اسم/باركود احد اصنافه الفرعية؛
     *   فيظهر الرئيسي كاملاً بكل اصنافه.
     * - الطول والكمية يطابقان طول القطعة وعدد القطع في صنف فرعي مطابقة تامة، او طول البند
     *   وكميته اذا لم تكن له اصناف فرعية.
     *
     * @param  array<string, mixed>  $mainItem  من for()
     * @return array{visible: bool, matched: array<int, int>}
     */
    public static function search(array $mainItem, ?string $search = '', mixed $length = null, mixed $quantity = null): array
    {
        $search = mb_strtolower(trim((string) $search));
        $length = self::measureFilter($length);
        $quantity = self::measureFilter($quantity);
        $hasMeasure = $length !== null || $quantity !== null;

        $textMatches = fn (?string ...$values): bool => collect($values)
            ->contains(fn (?string $value): bool => str_contains(mb_strtolower((string) $value), $search));

        $mainMatches = $search === '' || $textMatches($mainItem['name'], $mainItem['code'], $mainItem['barcode']);

        if ($mainItem['sub_items'] === []) {
            $measureMatches = ($length === null || ($mainItem['length'] !== null && $mainItem['length'] === $length))
                && ($quantity === null || $mainItem['quantity'] === $quantity);

            return ['visible' => $mainMatches && $measureMatches, 'matched' => []];
        }

        $matched = [];

        foreach ($mainItem['sub_items'] as $index => $subItem) {
            $subTextMatches = $search !== '' && $textMatches($subItem['name'], $subItem['barcode']);
            $measureMatches = ($length === null || $subItem['length'] === $length)
                && ($quantity === null || $subItem['count'] === $quantity);

            if ($hasMeasure ? ($measureMatches && ($mainMatches || $subTextMatches)) : $subTextMatches) {
                $matched[] = $index;
            }
        }

        return [
            'visible' => $hasMeasure ? $matched !== [] : ($mainMatches || $matched !== []),
            'matched' => $matched,
        ];
    }

    /**
     * الوزن الكلي والفعلي لبند الوزن مع قطعه (عدد × طول × وزن المتر لكل قطعة). صنف الكمية
     * يُعرض بعدده فتعود قيمه فارغة.
     *
     * @return array{total_weight: float|null, actual_weight: float|null, pieces: array<int, array{name: string, barcode: string, count: float, length: float, weight_per_meter: float, total_weight: float}>}
     */
    public static function weights(PurchaseOrderItem|ConsolidatedPurchaseOrderItem $item): array
    {
        if (! $item->isWeightPricedLine()) {
            return ['total_weight' => null, 'actual_weight' => null, 'pieces' => []];
        }

        $pieces = $item->pieces
            ->map(fn ($piece): array => [
                'name' => (string) $piece->description,
                'barcode' => (string) $piece->barcode,
                'count' => Number::normalizeQuantity($piece->piece_count),
                'length' => Number::normalizeQuantity($piece->piece_length),
                'weight_per_meter' => Number::normalizeQuantity($piece->weight_per_meter),
                'total_weight' => Number::normalizeQuantity($piece->total_weight),
            ])
            ->values()
            ->all();

        return [
            'total_weight' => Number::normalizeQuantity(array_sum(array_column($pieces, 'total_weight'))),
            // الخدمة تحفظ الوزن الفعلي null اذا لم يُدخل من الميزان.
            'actual_weight' => (float) $item->actual_weight > 0 ? Number::normalizeQuantity($item->actual_weight) : null,
            'pieces' => $pieces,
        ];
    }

    /**
     * قيمة حقل الطول او الكمية، او null اذا كان فارغاً او غير رقمي فيُتجاهل.
     */
    public static function measureFilter(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? Number::normalizeQuantity($value) : null;
    }

    /**
     * @param  Collection<int, Product>  $mainProducts
     * @param  array<int, string>  $categoryLabels
     * @return array<string, mixed>
     */
    private static function mainItemFor(PurchaseOrderItem|ConsolidatedPurchaseOrderItem $line, Collection $mainProducts, array $categoryLabels): array
    {
        $isWeightPriced = $line->isWeightPricedLine();
        $weights = self::weights($line);
        // بند الطلبية التجميعية لا يحفظ الطول؛ يؤخذ من بند طلبية الشراء المصدر.
        $length = $line instanceof PurchaseOrderItem ? $line->length : $line->sourcePurchaseOrderItem?->length;

        $main = [
            'name' => (string) $line->description,
            'code' => $line->product?->sku,
            'barcode' => $line->barcode,
            'line_is_main' => true,
            'is_weight_priced' => $isWeightPriced,
            'length' => filled($length) ? Number::normalizeQuantity($length) : null,
            'quantity' => Number::normalizeQuantity($line->quantity),
            'total_weight' => $isWeightPriced ? Number::normalizeQuantity($line->total_weight) : null,
            'actual_weight' => $weights['actual_weight'],
            'sub_items' => $weights['pieces'],
        ];

        if ($weights['pieces'] !== []) {
            return $main;
        }

        $mainProduct = $mainProducts->get($line->product?->main_product_id);
        $categoryId = self::categoryId($line);

        // البند صنف فرعي أُدخل وحده: رأسه صنفه الرئيسي، وهو صنفه الفرعي الوحيد.
        if ($mainProduct || ($isWeightPriced && isset($categoryLabels[$categoryId]))) {
            $main['name'] = $mainProduct ? (string) $mainProduct->name : $categoryLabels[$categoryId];
            $main['code'] = $mainProduct?->sku;
            $main['barcode'] = $mainProduct?->barcode;
            $main['line_is_main'] = false;
            $main['sub_items'] = [[
                'name' => (string) $line->description,
                'barcode' => (string) $line->barcode,
                'count' => Number::normalizeQuantity($line->quantity),
                'length' => $main['length'],
                'weight_per_meter' => filled($line->product?->weight_per_meter) ? Number::normalizeQuantity($line->product->weight_per_meter) : null,
                'total_weight' => $main['total_weight'],
            ]];
        }

        return $main;
    }

    private static function categoryId(PurchaseOrderItem|ConsolidatedPurchaseOrderItem $line): int
    {
        return (int) ($line instanceof PurchaseOrderItem ? $line->category_id : $line->sourcePurchaseOrderItem?->category_id);
    }
}
