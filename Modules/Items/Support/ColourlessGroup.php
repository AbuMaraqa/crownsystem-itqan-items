<?php

namespace Modules\Items\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;

/**
 * مقطع بالوزن (له طول ووزن متر) في تصنيف بالوزن لم يُضف له لون: يدخل مجموعة «تصنيفه — بلا لون»
 * (تُنشأ ان لم تكن)، فيظهر في الطلبيات كالمقاطع الملوّنة: سطر المجموعة وتحته المقطع صنفاً فرعياً،
 * وكيلوه وقيمته على المجموعة (قرار صاحب النظام 2026-11-28).
 *
 * لا يُنقل: ما له ألوان (أبناء)، وصنف الوزن بلا قياس قطعة (يُخزَّن بالكغم)، وما له حركات مخزون
 * (رصيده وقيمته على الصنف نفسه؛ يُذكر في التقرير ليُعالج يدوياً).
 */
final class ColourlessGroup
{
    /**
     * @param  list<int>|null  $productIds  null = كل الاصناف
     * @return array{assigned: int, groups_created: int, with_stock: list<int>}
     */
    public static function assign(?array $productIds = null, bool $dryRun = false): array
    {
        $prefix = config('items.table_prefix');
        $products = $prefix.'products';
        $candidates = DB::table($products.' as p')
            ->join($prefix.'products_categories as pc', 'pc.product_id', '=', 'p.id')
            ->join($prefix.'categories as c', 'c.id', '=', 'pc.category_id')
            ->where('p.pricing_type', 'weight')
            ->whereNull('p.category_finish_id')
            ->whereNull('p.deleted_at')
            ->where('c.pricing_type', 'weight')
            ->whereRaw('COALESCE(p.length, 0) * COALESCE(p.weight_per_meter, 0) > 0')
            ->whereNotExists(fn ($query) => $query->from($products.' as child')->whereColumn('child.parent_id', 'p.id')->whereNull('child.deleted_at'))
            ->when($productIds !== null, fn ($query) => $query->whereIn('p.id', $productIds ?: [0]))
            ->groupBy('p.id')
            ->selectRaw('p.id, MIN(pc.category_id) as category_id')
            ->pluck('category_id', 'id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $withStock = self::withStock(array_keys($candidates));
        $candidates = array_diff_key($candidates, array_flip($withStock));
        $report = ['assigned' => count($candidates), 'groups_created' => 0, 'with_stock' => $withStock];

        if ($dryRun || $candidates === []) {
            $report['groups_created'] = count(array_diff(array_unique($candidates), self::existingGroups(array_unique($candidates))));

            return $report;
        }

        $finishId = (int) Finish::system()->id;

        DB::transaction(function () use ($candidates, $finishId, $products, &$report): void {
            foreach (collect($candidates)->groupBy(fn (int $categoryId): int => $categoryId, true) as $categoryId => $items) {
                $group = CategoryFinish::query()->firstOrCreate(['category_id' => $categoryId, 'finish_id' => $finishId], ['is_active' => true]);
                $report['groups_created'] += $group->wasRecentlyCreated ? 1 : 0;

                foreach (array_chunk(array_keys($items->all()), 1000) as $chunk) {
                    DB::table($products)->whereIn('id', $chunk)->update(['category_finish_id' => $group->id, 'finish_id' => $finishId, 'updated_at' => now()]);
                }
            }
        });

        return $report;
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<int>  التصنيفات التي لها مجموعة «بلا لون» من قبل
     */
    private static function existingGroups(array $categoryIds): array
    {
        $finish = Finish::query()->where('is_system', true)->value('id');

        return CategoryFinish::query()->where('finish_id', $finish)->whereIn('category_id', $categoryIds ?: [0])->pluck('category_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private static function withStock(array $productIds): array
    {
        $movements = config('accounting.table_prefix').'stock_movements';

        if ($productIds === [] || ! Schema::hasTable($movements)) {
            return [];
        }

        $found = [];

        foreach (array_chunk($productIds, 1000) as $chunk) {
            array_push($found, ...DB::table($movements)->whereIn('product_id', $chunk)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all());
        }

        sort($found);

        return $found;
    }
}
