<?php

namespace Modules\Accounting\Support;

/**
 * حساب تكلفة أمر الإنتاج، في مكان واحد تستخدمه الشاشة (للعرض الفوري) والخدمة (للحفظ):
 *
 * - الصنف: الموزون (pricing_type = weight) وزنه × تكلفة الكغم، وغيره الكمية × تكلفة الوحدة.
 * - الأجر: الكمية (ساعات، ايام، قطع، او 1 للمبلغ المقطوع) × الأجر.
 * - الخدمة: الكمية × تكلفة الوحدة، داخلية كانت او لدى جهة خارجية.
 * - التكلفة الكلية = الاصناف + الأجور + الخدمات، وتكلفة الوحدة = الكلية ÷ الكمية المنتجة.
 * - الهامش = قيمة البيع - التكلفة الكلية، ونسبته من قيمة البيع.
 */
final class ProductionCost
{
    /**
     * @param  array<string, mixed>  $line
     */
    public static function materialTotal(array $line): float
    {
        $base = ($line['pricing_type'] ?? 'simple') === 'weight'
            ? self::number($line['weight'] ?? 0)
            : self::number($line['quantity'] ?? 0);

        return round($base * self::number($line['unit_cost'] ?? 0), 4);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function laborTotal(array $line): float
    {
        return round(self::number($line['quantity'] ?? 0) * self::number($line['rate'] ?? 0), 4);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function serviceTotal(array $line): float
    {
        return round(self::number($line['quantity'] ?? 0) * self::number($line['unit_cost'] ?? 0), 4);
    }

    /**
     * @param  iterable<int|string, array<string, mixed>>  $materials
     * @param  iterable<int|string, array<string, mixed>>  $labor
     * @param  iterable<int|string, array<string, mixed>>  $services
     * @return array{materials_cost: float, labor_cost: float, services_cost: float, total_cost: float, unit_cost: float, sales_amount: float, margin: float, margin_rate: float|null}
     */
    public static function summary(iterable $materials, iterable $labor, iterable $services, mixed $producedQuantity, mixed $salesAmount = 0): array
    {
        $materialsCost = round(collect($materials)->sum(fn (array $line): float => self::materialTotal($line)), 4);
        $laborCost = round(collect($labor)->sum(fn (array $line): float => self::laborTotal($line)), 4);
        $servicesCost = round(collect($services)->sum(fn (array $line): float => self::serviceTotal($line)), 4);
        $totalCost = round($materialsCost + $laborCost + $servicesCost, 4);
        $quantity = self::number($producedQuantity);
        $salesAmount = round(self::number($salesAmount), 4);
        $margin = round($salesAmount - $totalCost, 4);

        return [
            'materials_cost' => $materialsCost,
            'labor_cost' => $laborCost,
            'services_cost' => $servicesCost,
            'total_cost' => $totalCost,
            'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 4) : $totalCost,
            'sales_amount' => $salesAmount,
            'margin' => $margin,
            'margin_rate' => $salesAmount > 0 ? round($margin / $salesAmount * 100, 2) : null,
        ];
    }

    /**
     * نصيب كل صنف منتج من التكلفة الكلية: بالوزن ان كان لكل الاصناف المنتجة وزن (كمقاطع
     * الالمنيوم)، والا بالكمية؛ والفرق من التقريب على آخر صنف فيبقى المجموع مساوياً للكلية.
     *
     * @param  iterable<int|string, array<string, mixed>>  $outputs
     * @return list<float>
     */
    public static function allocate(iterable $outputs, mixed $totalCost): array
    {
        $outputs = array_values(collect($outputs)->all());
        $total = round(self::number($totalCost), 4);
        $byWeight = $outputs !== [] && collect($outputs)->every(fn (array $line): bool => self::number($line['weight'] ?? 0) > 0);
        $bases = array_map(fn (array $line): float => self::number($byWeight ? ($line['weight'] ?? 0) : ($line['quantity'] ?? 0)), $outputs);
        $sum = array_sum($bases);

        if ($sum <= 0) {
            return array_map(fn (int $index): float => $index === 0 ? $total : 0.0, array_keys($outputs));
        }

        $shares = array_map(fn (float $base): float => round($total * $base / $sum, 4), $bases);
        $last = array_key_last($shares);
        $shares[$last] = round($total - (array_sum($shares) - $shares[$last]), 4);

        return $shares;
    }

    /**
     * الكمية المنتجة: مجموع كميات الاصناف المنتجة ان وُجدت، والا ما كُتب في الأمر.
     *
     * @param  iterable<int|string, array<string, mixed>>  $outputs
     */
    public static function producedQuantity(iterable $outputs, mixed $fallback): float
    {
        $quantity = round(collect($outputs)->sum(fn (array $line): float => self::number($line['quantity'] ?? 0)), 4);

        return $quantity > 0 ? $quantity : self::number($fallback);
    }

    public static function number(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
