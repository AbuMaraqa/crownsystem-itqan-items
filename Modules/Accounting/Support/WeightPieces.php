<?php

namespace Modules\Accounting\Support;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Settings\GeneralSettings;
use Modules\Items\Entities\Product;

/**
 * الاصناف الفرعية (قطع) داخل سطر صنف وزن: وزن القطعة = العدد × طول القطعة × وزن المتر،
 * ووزن السطر مجموعها، او الوزن الفعلي من الميزان ان أُدخل.
 */
class WeightPieces
{
    /**
     * @param  array<int, array<string, mixed>>  $pieces
     * @param  array<int, string>  $keep  مفاتيح اضافية تُنقل كما هي (مثل purchase_order_item_piece_id)
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $pieces, array $keep = []): array
    {
        $pieces = array_values(array_filter($pieces, fn (array $piece): bool => filled($piece['product_id'] ?? null)));
        $products = Product::query()->with('translations')->whereIn('id', array_column($pieces, 'product_id'))->get()->keyBy('id');

        return array_map(function (array $piece, int $index) use ($products, $keep): array {
            $product = $products->get((int) $piece['product_id']);
            $count = max(self::decimal($piece['piece_count'] ?? 1, 1), 0);
            $length = max(self::decimal($piece['piece_length'] ?? $product?->length ?? 0), 0);
            $weightPerMeter = max(self::decimal($piece['weight_per_meter'] ?? $product?->weight_per_meter ?? 0), 0);

            $normalized = [
                'id' => $piece['id'] ?? null,
                'product_id' => (int) $piece['product_id'],
                'barcode' => $piece['barcode'] ?? $product?->barcode,
                'description' => $piece['description'] ?? $product?->name,
                'piece_count' => $count,
                'piece_length' => $length,
                'weight_per_meter' => $weightPerMeter,
                'total_weight' => round($count * $length * $weightPerMeter, 4),
                'sort_order' => $index,
                'created_by' => $piece['created_by'] ?? auth()->id(),
            ];

            foreach ($keep as $key) {
                $normalized[$key] = filled($piece[$key] ?? null) ? $piece[$key] : null;
            }

            return $normalized;
        }, $pieces, array_keys($pieces));
    }

    /**
     * جزء الوزن من سطر مستند يُفوتر بالكيلوغرام (عرض سعر، فاتورة، مرتجع): القطع، وزنها
     * الكلي، الوزن الفعلي، والكمية المفوترة: الفعلي ان أُدخل، والا صفر ولو حسبت القطع وزناً
     * (قرار صاحب النظام، كما في النظام القديم). وزن القطع يبقى كما هو لحركة المخزون. سطر
     * وزن قديم بلا قطع كميته هي وزنه: pricing_weight = null تعني الكمية كما أُدخلت.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $keep  مفاتيح ربط القطع بالمصدر (مثل sales_order_item_piece_id)
     * @return array{pieces: array<int, array<string, mixed>>, total_weight: float, actual_weight: ?float, pricing_weight: ?float}
     */
    public static function line(array $item, ?Product $product, array $keep = []): array
    {
        if ($product?->pricing_type !== 'weight') {
            return ['pieces' => [], 'total_weight' => 0.0, 'actual_weight' => null, 'pricing_weight' => null];
        }

        $pieces = self::normalize($item['pieces'] ?? [], $keep);
        $actual = max(self::decimal($item['actual_weight'] ?? 0), 0);

        return [
            'pieces' => $pieces,
            'total_weight' => round(array_sum(array_column($pieces, 'total_weight')), 4),
            'actual_weight' => $actual > 0 ? $actual : null,
            'pricing_weight' => $pieces !== [] ? self::pricingWeight($pieces, $actual) : ($actual > 0 ? $actual : null),
        ];
    }

    /**
     * حفظ قطع السطر بعد normalize(): القطعة التي لها id تُحدَّث في مكانها (فيبقى معرفها
     * ثابتاً لمن يرتبط بها، كالمرتجعات)، والجديدة تُنشأ، وما لم يعد موجوداً يُحذف.
     *
     * @param  array<int, array<string, mixed>>  $pieces
     */
    public static function sync(HasMany $relation, array $pieces): void
    {
        $incomingIds = array_values(array_filter(array_column($pieces, 'id')));

        (clone $relation)->when($incomingIds !== [], fn ($query) => $query->whereNotIn('id', $incomingIds))->delete();

        $existing = (clone $relation)->whereIn('id', $incomingIds)->get()->keyBy('id');

        foreach ($pieces as $piece) {
            $id = $piece['id'] ?? null;
            unset($piece['id']);

            if ($id && $model = $existing->get($id)) {
                // منشئ القطعة لا يتغير بتعديلها.
                unset($piece['created_by']);
                $model->update($piece);
            } else {
                (clone $relation)->create($piece);
            }
        }
    }

    /**
     * وزن قطعة واحدة: العدد × طول القطعة × وزن المتر.
     *
     * @param  array<string, mixed>  $piece
     */
    public static function weight(array $piece): float
    {
        return round(
            max(self::decimal($piece['piece_count'] ?? 0), 0)
            * max(self::decimal($piece['piece_length'] ?? 0), 0)
            * max(self::decimal($piece['weight_per_meter'] ?? 0), 0),
            4
        );
    }

    /**
     * الوزن الذي يُسعَّر به السطر: الفعلي ان وُجد، والا صفر فيكون المبلغ صفراً.
     *
     * @param  array<int, array<string, mixed>>  $pieces  قطع بعد normalize()
     */
    public static function pricingWeight(array $pieces, mixed $actualWeight = null): float
    {
        return max(self::decimal($actualWeight), 0);
    }

    /**
     * نسبة فرق الوزن كما في النظام القديم: |الفعلي − المحسوب| ÷ المحسوب × 100، و null اذا
     * لم يكن المحسوب موجباً. الوزن الفعلي غير المُدخل يعطي 100%؛ شاشة البنود لا تنبّه عليه.
     */
    public static function weightVariancePercent(mixed $actual, mixed $calculated): ?float
    {
        $calculated = self::decimal($calculated);

        if ($calculated <= 0) {
            return null;
        }

        return round(abs(self::decimal($actual) - $calculated) / $calculated * 100, 4);
    }

    /**
     * هل تجاوز الفرق النسبة المسموحة؟ تنبيه للعرض فقط، ولا يمنع الحفظ في اي مسار.
     */
    public static function weightVarianceExceeded(mixed $actual, mixed $calculated): bool
    {
        $percent = self::weightVariancePercent($actual, $calculated);

        return $percent !== null && $percent > self::weightVarianceTolerance();
    }

    public static function weightVarianceTolerance(): float
    {
        return app(GeneralSettings::class)->weight_variance_tolerance;
    }

    private static function decimal(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? round((float) $value, 4) : $default;
    }
}
