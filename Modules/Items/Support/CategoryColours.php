<?php

namespace Modules\Items\Support;

use Modules\Accounting\Support\Number;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;

/**
 * ألوان صنف الوزن التي يختار منها تبويب «الألوان والمتغيرات»: مجموعات السعر (التصنيف × اللون)
 * المفعّلة في تصنيفات الصنف، مع سعر بيع الكيلو في كل قائمة سعر (للعرض فقط؛ يُعدَّل في صفحة التصنيف).
 * مصدر واحد للتبويب ولجدول اسعار الكيلو في تبويب السعر.
 */
final class CategoryColours
{
    /**
     * @param  array<int, int|string>  $categoryIds  تصنيفات الصنف
     * @param  array<int, int|string>  $include  مجموعات سعر محفوظة على متغيرات الصنف، تظهر وإن كانت من تصنيف آخر او معطّلة
     * @return array{lists: list<array{id: int, name: string}>, colours: list<array{id: int, finish_id: int, name: string, category: string, swatch: ?string, is_active: bool, prices: array<int, ?string>}>}
     */
    public static function for(array $categoryIds, array $include = []): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $include = array_values(array_unique(array_map('intval', $include)));

        $lists = collect(self::priceLists());

        if ($categoryIds === [] && $include === []) {
            return ['lists' => $lists->all(), 'colours' => []];
        }

        $groups = CategoryFinish::query()
            ->with(['category.translations', 'finish.attributeValue', 'prices' => fn ($query) => $query->whereIn('price_list_id', $lists->pluck('id'))])
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('category_id', $categoryIds)->where('is_active', true))
                ->orWhereIn('id', $include))
            ->whereHas('finish')
            ->get()
            ->sortBy(fn (CategoryFinish $group) => [
                in_array((int) $group->category_id, $categoryIds, true) ? 0 : 1,
                (int) $group->finish->sort_order,
                (string) $group->finish->name,
            ])
            ->values();

        return [
            'lists' => $lists->all(),
            'colours' => $groups->map(fn (CategoryFinish $group) => [
                'id' => (int) $group->id,
                'finish_id' => (int) $group->finish_id,
                'name' => $group->labelFor($categoryIds),
                'category' => (string) $group->category?->name,
                'swatch' => $group->finish->attributeValue?->color_code ?: null,
                'is_active' => (bool) $group->is_active,
                'prices' => $group->prices->mapWithKeys(fn ($price) => [
                    (int) $price->price_list_id => $price->sell_price === null ? null : Number::money($price->sell_price),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * جدول «الألوان وأسعار الكيلو» في صفحة التصنيف: كل ألوانه (والمعطّلة) بسعر البيع والشراء في
     * كل قائمة، وعدد الاصناف على كل لون (اللون الذي عليه اصناف لا يُحذف).
     *
     * @return list<array{id: int, finish_id: int, name: string, swatch: ?string, is_active: bool, used: int, prices: array<int, array{sell: ?string, buy: ?string}>}>
     */
    public static function forCategory(Category $category): array
    {
        $used = Product::query()
            ->whereIn('category_finish_id', $category->categoryFinishes()->select('id'))
            ->groupBy('category_finish_id')
            ->selectRaw('category_finish_id, COUNT(*) as items')
            ->pluck('items', 'category_finish_id');
        $money = fn ($value): ?string => $value === null ? null : Number::money($value);

        return $category->categoryFinishes()
            ->with(['finish.attributeValue', 'prices'])
            ->get()
            ->sortBy(fn (CategoryFinish $group) => [(int) $group->finish?->sort_order, (string) $group->finish?->name])
            ->map(fn (CategoryFinish $group) => [
                'id' => (int) $group->id,
                'finish_id' => (int) $group->finish_id,
                'name' => (string) $group->finish?->name,
                'swatch' => $group->finish?->attributeValue?->color_code ?: null,
                'is_active' => (bool) $group->is_active,
                'used' => (int) ($used[$group->id] ?? 0),
                'prices' => $group->prices->mapWithKeys(fn ($price) => [
                    (int) $price->price_list_id => ['sell' => $money($price->sell_price), 'buy' => $money($price->buy_price)],
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * قوائم السعر المفعّلة: اعمدة الجدول.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function priceLists(): array
    {
        return collect(PriceList::options())->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all();
    }
}
