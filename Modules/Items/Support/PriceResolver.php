<?php

namespace Modules\Items\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use InvalidArgumentException;
use Modules\Accounting\Services\CustomerHistoryService;
use Modules\Accounting\Support\PartyPricing;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Entities\ProductUnit;

/**
 * سعر الصنف في قائمة اسعار. الصف الذي له قائمة مصدر يُحسب: سعر الصنف نفسه بالوحدة
 * نفسها وبالجهة نفسها في قائمة المصدر × المعامل. مستوى واحد فقط كما في النظام
 * القديم: يؤخذ من المصدر سعره المحفوظ ولو كان هو ايضاً معادلة، فلا دوران.
 * المصدر المفقود او غير الصالح يرجع الى سعر الصف نفسه.
 *
 * forDocumentLines هو مدخل سطور المستندات (ProductSearch).
 */
final class PriceResolver
{
    public const SELL = 'sell';

    public const BUY = 'buy';

    /**
     * @return string|null السعر بأربع منازل، او null اذا لا صف للصنف في القائمة
     */
    public static function resolve(Product $product, PriceList $list, ?ProductUnit $unit = null, string $side = self::SELL): ?string
    {
        $column = self::column($side);
        $row = self::row($product, $list->id, $unit);

        if (! $row) {
            return null;
        }

        $source = $row->source_price_list_id ? self::row($product, (int) $row->source_price_list_id, $unit) : null;

        return self::price($row, $source, $list, $column);
    }

    /**
     * سعر سطور مستند بترتيب النظام القديم، لكل صنف من الاصناف المعطاة:
     *  1. آخر سعر للطرف والصنف من آخر سطر مرحّل، لمن فُعّل له الخيار، ان لم يكن صفراً.
     *  2. قائمة اسعار الطرف: جهة البيع للمبيعات والشراء للمشتريات، ان لم يكن صفراً.
     *  3. $fallback: السعر الاساسي او متوسط التكلفة + هامش الزبون (ProductSearch)، تراجع مقصود حتى
     *     لا يتغير سعر الزبائن الحاليين الذين لم تُضبط لهم قائمة.
     * بلا طرف لا سعر تلقائي. سعر سطر الوزن للصنف الرئيسي، فالمقطع يأخذ سعر رئيسيه.
     *
     * @param  iterable<Product>  $products
     * @param  Closure(Product): float  $fallback
     * @return array<int, float> السعر مفهرساً بمعرّف الصنف
     */
    public static function forDocumentLines(iterable $products, LinePriceContext $context, string $side, Closure $fallback): array
    {
        $products = collect($products);

        if ($context->partyId === null) {
            return $products->mapWithKeys(fn (Product $product): array => [(int) $product->id => 0.0])->all();
        }

        $mains = Product::query()
            ->whereIn('id', $products->filter(fn (Product $product): bool => self::isPiece($product))->pluck('main_product_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');
        $owners = $products->mapWithKeys(fn (Product $product): array => [
            (int) $product->id => self::isPiece($product) ? ($mains->get($product->main_product_id) ?? $product) : $product,
        ]);
        $ownerIds = $owners->map(fn (Product $owner): int => (int) $owner->id)->unique()->values()->all();

        ['use_last_price' => $useLastPrice, 'price_list' => $list] = PartyPricing::for($context->partyId);

        $lastPrices = $useLastPrice
            ? app(CustomerHistoryService::class)->lastPostedPrices($context->partyId, $ownerIds, $side)
            : [];
        $listPrices = $list ? self::resolveMany($ownerIds, $list, $side) : [];

        return $owners->map(function (Product $owner) use ($lastPrices, $listPrices, $context, $fallback): float {
            $last = $lastPrices[(int) $owner->id] ?? null;

            // السعر بعملة مستنده: يُحوَّل الى عملة الاساس ثم الى عملة المستند الجديد.
            if ($last && $last['price'] > 0) {
                return round($last['price'] * $last['exchange_rate'] / $context->exchangeRate, 4);
            }

            $listPrice = (float) ($listPrices[(int) $owner->id] ?? 0);

            return $listPrice > 0 ? round($listPrice / $context->exchangeRate, 4) : $fallback($owner);
        })->all();
    }

    private static function isPiece(Product $product): bool
    {
        return $product->pricing_type === 'weight' && filled($product->main_product_id);
    }

    /**
     * resolve() لعدة اصناف بالوحدة الاساسية، باستعلامين بدل استعلام لكل صنف
     * (اضافة تصنيف كامل الى المستند تعني مئات الاصناف).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private static function resolveMany(array $productIds, PriceList $list, string $side): array
    {
        $column = self::column($side);
        $rows = ProductPrice::query()
            ->where('price_list_id', $list->id)
            ->whereIn('product_id', $productIds)
            ->whereNull('product_unit_id')
            ->get()
            ->keyBy('product_id');
        $sources = ProductPrice::query()
            ->whereIn('price_list_id', $rows->pluck('source_price_list_id')->filter()->unique()->values()->all())
            ->whereIn('product_id', $productIds)
            ->whereNull('product_unit_id')
            ->get()
            ->keyBy(fn (ProductPrice $row): string => $row->price_list_id.':'.$row->product_id);

        return $rows
            ->mapWithKeys(fn (ProductPrice $row): array => [(int) $row->product_id => self::price(
                $row,
                $sources->get($row->source_price_list_id.':'.$row->product_id),
                $list,
                $column,
            )])
            ->all();
    }

    /**
     * سعر الصف، او سعر مصدره × المعامل اذا كان صفاً محسوباً بمصدر صالح.
     */
    private static function price(ProductPrice $row, ?ProductPrice $source, PriceList $list, string $column): string
    {
        $sourceId = $row->source_price_list_id;
        $factor = $row->source_factor;

        if (! $source || ! $sourceId || (int) $sourceId === (int) $list->id || $factor === null || ! ExactDecimal::of($factor)->isPositive()) {
            return self::money($row->{$column});
        }

        return self::money(ExactDecimal::of($source->{$column})->multipliedBy(ExactDecimal::of($factor)));
    }

    /**
     * الحد الادنى اذا كان السعر غير الصفري اقل من سعر البيع غير الصفري في قائمة
     * الحد الادنى، والا null. لا يمنع شيئاً بنفسه: القرار لمن يستدعيه.
     */
    public static function minimumViolation(Product $product, ?ProductUnit $unit, PriceList $minList, mixed $price): ?string
    {
        $price = ExactDecimal::of($price, 'price');
        $minimum = self::resolve($product, $minList, $unit, self::SELL);

        if ($minimum === null || $price->isZero()) {
            return null;
        }

        $minimum = ExactDecimal::of($minimum);

        return ! $minimum->isZero() && $price->isLessThan($minimum) ? (string) $minimum : null;
    }

    private static function row(Product $product, int $listId, ?ProductUnit $unit): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('price_list_id', $listId)
            ->where('product_id', $product->id)
            ->when(
                $unit,
                fn ($query) => $query->where('product_unit_id', $unit->id),
                fn ($query) => $query->whereNull('product_unit_id'),
            )
            ->first();
    }

    private static function column(string $side): string
    {
        return match ($side) {
            self::SELL => 'sell_price',
            self::BUY => 'buy_price',
            default => throw new InvalidArgumentException("Unknown price side: {$side}"),
        };
    }

    private static function money(BigDecimal|string|null $value): string
    {
        return (string) ExactDecimal::of($value ?? '0')->toScale(ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp);
    }
}
