<?php

namespace Modules\Items\Support;

use Brick\Math\RoundingMode;
use Modules\Accounting\Support\PartyPricing;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\PriceList;

/**
 * سعر الكيلو لسطر مجموعة السعر (التصنيف × اللون) في المستندات، كما يسعّر PriceResolver سطر الصنف:
 *  1. قائمة اسعار الطرف (PartyPricing): البيع للمبيعات والشراء للمشتريات، ان لم يكن صفراً؛
 *  2. والا القائمة العامة: اول قائمة مفعّلة بترتيبها؛
 *  3. والا صفر، ويكتب المستخدم السعر.
 * بلا طرف لا سعر تلقائي. الصف المحسوب = سعر مصدره × المعامل. السعر بعملة المستند.
 * التصميم: docs/superpowers/specs/2026-11-22-catalog-phase-2-document-lines-design.md (D2-5).
 */
final class KgPriceResolver
{
    /**
     * @param  array<int, int|string>  $groupIds  مجموعات السعر (items_category_finishes)
     * @return array<int, float> سعر الكيلو مفهرساً بالمجموعة
     */
    public static function forGroups(array $groupIds, LinePriceContext $context, string $side): array
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $zero = array_fill_keys($groupIds, 0.0);

        if ($groupIds === [] || $context->partyId === null) {
            return $zero;
        }

        $column = $side === PriceResolver::BUY ? 'buy_price' : 'sell_price';
        $partyList = PartyPricing::for($context->partyId)['price_list'];
        $generalList = PriceList::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->value('id');
        $lists = array_values(array_unique(array_filter([$partyList?->id, $generalList])));

        $rows = CategoryFinishPrice::query()
            ->whereIn('category_finish_id', $groupIds)
            ->whereIn('price_list_id', $lists)
            ->get()
            ->keyBy(fn (CategoryFinishPrice $row): string => $row->category_finish_id.':'.$row->price_list_id);
        $sources = CategoryFinishPrice::query()
            ->whereIn('category_finish_id', $groupIds)
            ->whereIn('price_list_id', $rows->pluck('source_price_list_id')->filter()->unique()->values()->all())
            ->get()
            ->keyBy(fn (CategoryFinishPrice $row): string => $row->category_finish_id.':'.$row->price_list_id);

        $priceIn = function (int $groupId, ?int $listId) use ($rows, $sources, $column): float {
            $row = $listId ? $rows->get($groupId.':'.$listId) : null;

            if (! $row) {
                return 0.0;
            }

            $source = $row->source_price_list_id ? $sources->get($groupId.':'.$row->source_price_list_id) : null;
            $factor = $row->source_factor;

            if ($source && (int) $row->source_price_list_id !== (int) $listId && $factor !== null && ExactDecimal::of($factor)->isPositive()) {
                return (float) (string) ExactDecimal::of($source->{$column})->multipliedBy(ExactDecimal::of($factor))->toScale(ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp);
            }

            return (float) $row->{$column};
        };

        foreach ($groupIds as $groupId) {
            $price = $priceIn($groupId, $partyList?->id);

            if ($price <= 0) {
                $price = $priceIn($groupId, $generalList ? (int) $generalList : null);
            }

            $zero[$groupId] = round($price / $context->exchangeRate, 4);
        }

        return $zero;
    }
}
