<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Support\Number;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;

/**
 * ألوان التصنيف (مجموعات السعر) واسعار الكيلو من صفحة التصنيف. كل اصناف التصنيف بهذا اللون تتبع
 * هذا السعر، فلا سعر كيلو على الصنف.
 *
 * - الصف بلا رقم: لون يُضاف (من القائمة، او جديد باسمه فيُنشأ مع صورته في سمة «اللون»).
 * - اللون الذي لم يعد في الجدول يُحذف مع اسعاره، الا اذا كان عليه صنف فيُرفض الحفظ (يُعطَّل بدلاً من ذلك).
 * - السعر الفارغ (البيع والشراء) يحذف سعر القائمة. السعر المكتوب باليد لم يعد مشتقاً من قائمة اخرى.
 */
class CategoryColourService
{
    public function __construct(private readonly FinishService $finishes) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  كما تحققت منها CategoryColoursRequest
     *
     * @throws ValidationException لون عليه اصناف حُذف من الجدول
     */
    public function sync(Category $category, array $rows, ?int $userId): void
    {
        $listIds = array_keys(PriceList::options());

        DB::transaction(function () use ($category, $rows, $listIds, $userId): void {
            $existing = $category->categoryFinishes()->get()->keyBy('id');
            $kept = [];

            foreach (array_values($rows) as $row) {
                $finishId = filled($row['finish_id'] ?? null)
                    ? (int) $row['finish_id']
                    : $this->finishes->findOrCreate((string) $row['name'], $userId)->id;

                $group = filled($row['id'] ?? null) ? $existing->get((int) $row['id']) : null;
                $group ??= CategoryFinish::query()->firstOrNew(['category_id' => $category->id, 'finish_id' => $finishId]);
                $group->fill(['finish_id' => $finishId, 'is_active' => (bool) ($row['is_active'] ?? true)])->save();
                $kept[] = (int) $group->id;

                $this->savePrices($group, (array) ($row['prices'] ?? []), $listIds, $userId);
            }

            $removed = $existing->except($kept);
            $used = $removed->filter(fn (CategoryFinish $group) => Product::withTrashed()->where('category_finish_id', $group->id)->exists());

            if ($used->isNotEmpty()) {
                $used->load('finish');

                throw ValidationException::withMessages(['rows' => __('These colours have items and cannot be removed; deactivate them instead: :names', [
                    'names' => $used->map(fn (CategoryFinish $group) => (string) $group->finish?->name)->join('، '),
                ])]);
            }

            if ($removed->isNotEmpty()) {
                CategoryFinishPrice::query()->whereIn('category_finish_id', $removed->modelKeys())->delete();
                CategoryFinish::query()->whereKey($removed->modelKeys())->delete();
            }
        });
    }

    /**
     * @param  array<int|string, mixed>  $prices  رقم القائمة => {sell, buy}
     * @param  list<int>  $listIds  القوائم المفعّلة؛ غيرها لا يُكتب
     */
    private function savePrices(CategoryFinish $group, array $prices, array $listIds, ?int $userId): void
    {
        foreach ($prices as $listId => $price) {
            if (! in_array((int) $listId, $listIds, true) || ! is_array($price)) {
                continue;
            }

            $sell = filled($price['sell'] ?? null) ? (string) $price['sell'] : null;
            $buy = filled($price['buy'] ?? null) ? (string) $price['buy'] : null;
            $match = ['category_finish_id' => $group->id, 'price_list_id' => (int) $listId];

            if ($sell === null && $buy === null) {
                CategoryFinishPrice::query()->where($match)->delete();

                continue;
            }

            // العمودان بلا null (صفر افتراضي، كما ينقلهما ItqanPricesStage): الجانب الفارغ صفر.
            $sell ??= '0';
            $buy ??= '0';
            $row = CategoryFinishPrice::query()->firstOrNew($match, ['created_by' => $userId]);

            // سعر تغيّر باليد لم يعد مشتقاً من قائمة اخرى.
            if (! $row->exists || Number::money($row->sell_price) !== Number::money($sell) || Number::money($row->buy_price) !== Number::money($buy)) {
                $row->fill(['source_price_list_id' => null, 'source_factor' => null]);
            }

            $row->fill(['sell_price' => $sell, 'buy_price' => $buy])->save();
        }
    }
}
