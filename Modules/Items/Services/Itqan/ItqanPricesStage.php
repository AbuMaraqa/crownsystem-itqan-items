<?php

declare(strict_types=1);

namespace Modules\Items\Services\Itqan;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\ItqanImportStage;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Services\PriceListService;

/**
 * مرحلة الاسعار: قوائم إتقان (pricelists) وأسعار الاصناف فيها (itemsprices).
 * - القائمة تُربط بمعرّفها القديم في legacy_links، فإعادة النقل تحدّثها ولا تكررها.
 * - السعر يُعرف هدفه من legacy_links التي كتبتها مرحلة الاصناف: سعر عقدة تصنيف الالمنيوم
 *   هو سعر الكيلو لـ (التصنيف × اللون)، وسعر الورقة سعر الصنف المربوط بها (route()).
 * - اسعار قوائم محذوفة في إتقان لا تظهر فيه، فلا تُنقل وتُعدّ في التقرير.
 * - إتقان عندك بلا معادلات اسعار ولا اسعار بالوحدة؛ ان ظهرت تُعدّ ولا تُنقل حتى يُبنى لها نقل.
 */
final class ItqanPricesStage implements ItqanImportStage
{
    public function __construct(
        private readonly PriceListService $lists,
        private readonly LegacyLinks $links,
    ) {}

    public function key(): string
    {
        return 'prices';
    }

    public function dependsOn(): array
    {
        return ['items'];
    }

    public function run(ItqanSource $source, bool $dryRun, callable $log): array
    {
        $report = [
            'old_price_lists' => $source->count('pricelists'),
            'price_lists_created' => 0,
            'price_lists_updated' => 0,
            'old_prices' => $source->count('itemsprices'),
        ];

        $listIds = $this->importLists($source, $dryRun, $report);

        return $this->importPrices($source->rows('itemsprices'), $listIds, $dryRun, $log) + $report + ['dry_run' => $dryRun];
    }

    /**
     * الى اين يذهب سعر من itemsprices: عقدة تصنيف الالمنيوم ← سعر الكيلو لمجموعة السعر
     * (التصنيف × اللون)، والورقة ← سعر الصنف المربوط بها (الابن، او الاب).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $finishLinks  رقم العقدة القديمة => CategoryFinish
     * @param  array<string, int>  $productLinks  رقم الورقة القديمة => Product
     * @return array{target: 'kg'|'product'|'missing', id: ?int}
     */
    public static function route(array $row, array $finishLinks, array $productLinks): array
    {
        $id = trim((string) $row['ItemId']);

        return match (true) {
            isset($finishLinks[$id]) => ['target' => 'kg', 'id' => $finishLinks[$id]],
            isset($productLinks[$id]) => ['target' => 'product', 'id' => $productLinks[$id]],
            default => ['target' => 'missing', 'id' => null],
        };
    }

    /**
     * يكتب أسعار itemsprices عبر روابط مرحلة الاصناف. ورقتان قديمتان مربوطتان بالصنف
     * نفسه (لونان لم يُنشأ لهما ابن) يُكتب لهما سعر واحد، الاخير، ويُعدّان في التقرير.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $listIds  رقم القائمة في إتقان => معرّفها الجديد
     * @return array<string, int>
     */
    public function importPrices(iterable $rows, array $listIds, bool $dryRun, callable $log): array
    {
        $report = [
            'prices_written' => 0,
            'kg_prices_written' => 0,
            'prices_merged_into_parent' => 0,
            'prices_on_deleted_lists' => 0,
            'prices_for_missing_items' => 0,
            'prices_per_unit_skipped' => 0,
            'prices_with_equation_skipped' => 0,
        ];

        $finishLinks = $this->links->map('items', CategoryFinish::class);
        $productLinks = $this->links->map('items', Product::class);
        $now = now();
        $userId = auth()->id();
        $products = [];
        $kg = [];

        foreach ($rows as $row) {
            $listKey = (string) $row['PriceListId'];

            if (! array_key_exists($listKey, $listIds)) {
                $report['prices_on_deleted_lists']++;

                continue;
            }

            if (trim((string) $row['itemunit']) !== '') {
                $report['prices_per_unit_skipped']++;

                continue;
            }

            if (trim((string) $row['Equation']) !== '') {
                $report['prices_with_equation_skipped']++;

                continue;
            }

            $target = self::route($row, $finishLinks, $productLinks);
            $prices = [
                'price_list_id' => $listIds[$listKey],
                'sell_price' => $this->money($row['SellPrice']),
                'buy_price' => $this->money($row['BuyPrice']),
                'source_price_list_id' => null,
                'source_factor' => null,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($target['target'] === 'missing') {
                $report['prices_for_missing_items']++;

                continue;
            }

            if ($target['target'] === 'kg') {
                $kg[$listIds[$listKey].'|'.$target['id']] = ['category_finish_id' => $target['id']] + $prices;

                continue;
            }

            $key = $listIds[$listKey].'|'.$target['id'];
            if (isset($products[$key])) {
                $report['prices_merged_into_parent']++;
            }
            $products[$key] = ['product_id' => $target['id'], 'product_unit_id' => null] + $prices;
        }

        $report['prices_written'] = count($products);
        $report['kg_prices_written'] = count($kg);

        if ($dryRun) {
            return $report;
        }

        $updates = ['sell_price', 'buy_price', 'source_price_list_id', 'source_factor', 'updated_at'];

        DB::transaction(function () use ($products, $kg, $updates, $log): void {
            foreach (array_chunk(array_values($products), 1000) as $i => $chunk) {
                DB::table((new ProductPrice)->getTable())->upsert($chunk, ['price_list_id', 'product_id', 'unit_key'], $updates);
                $log('Prices written: '.min(($i + 1) * 1000, count($products)));
            }

            foreach (array_chunk(array_values($kg), 1000) as $chunk) {
                DB::table((new CategoryFinishPrice)->getTable())->upsert($chunk, ['category_finish_id', 'price_list_id'], $updates);
            }
            $log('Kg prices written: '.count($kg));
        });

        return $report;
    }

    /**
     * @param  array<string, int|bool|string>  $report
     * @return array<string, int> رقم القائمة في إتقان => معرّفها الجديد (او 0 في التجربة)
     */
    private function importLists(ItqanSource $source, bool $dryRun, array &$report): array
    {
        $existing = $this->links->map('pricelists', PriceList::class);
        $ids = [];
        $sort = 0;

        foreach ($source->rows('pricelists') as $row) {
            $key = (string) $row['PriceListId'];
            $data = [
                'name' => trim((string) $row['PriceListString']) ?: 'Price list '.$key,
                'is_active' => strtoupper(trim((string) $row['isActive'])) === 'Y',
                'sort_order' => $sort++,
            ];

            $list = isset($existing[$key]) ? PriceList::query()->find($existing[$key]) : null;
            $report[$list ? 'price_lists_updated' : 'price_lists_created']++;

            if ($dryRun) {
                $ids[$key] = $list?->getKey() ?? 0;

                continue;
            }

            $list = $list
                ? $this->lists->update($list, $data, 'ar')
                : $this->lists->create($data + ['same_buy_sell' => false], 'ar', auth()->id());

            $ids[$key] = (int) $list->getKey();
        }

        if (! $dryRun) {
            $this->links->remember('pricelists', PriceList::class, $ids);
        }

        return $ids;
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 4, '.', '') : '0.0000';
    }
}
