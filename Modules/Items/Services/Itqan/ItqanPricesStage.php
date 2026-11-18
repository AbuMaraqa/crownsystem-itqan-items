<?php

declare(strict_types=1);

namespace Modules\Items\Services\Itqan;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\ItqanImportStage;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Services\PriceListService;

/**
 * مرحلة الاسعار: قوائم إتقان (pricelists) وأسعار الاصناف فيها (itemsprices).
 * - القائمة تُربط بمعرّفها القديم في legacy_links، فإعادة النقل تحدّثها ولا تكررها.
 * - السعر يُكتب upsert على (القائمة، الصنف، الوحدة)، والصنف يُعرف بـ sku = ItemId.
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
            'prices_written' => 0,
            'prices_on_deleted_lists' => 0,
            'prices_for_missing_items' => 0,
            'prices_per_unit_skipped' => 0,
            'prices_with_equation_skipped' => 0,
        ];

        $listIds = $this->importLists($source, $dryRun, $report);

        $rows = [];
        foreach ($source->rows('itemsprices') as $row) {
            $rows[] = $row;
        }

        $skus = array_values(array_unique(array_map(fn (array $row): string => trim((string) $row['ItemId']), $rows)));
        $productIds = $this->productIdsBySku($skus);
        $now = now();
        $userId = auth()->id();
        $batch = [];

        foreach ($rows as $row) {
            $listKey = (string) $row['PriceListId'];
            $sku = trim((string) $row['ItemId']);

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

            if (! isset($productIds[$sku])) {
                $report['prices_for_missing_items']++;

                continue;
            }

            $batch[] = [
                'price_list_id' => $listIds[$listKey],
                'product_id' => $productIds[$sku],
                'product_unit_id' => null,
                'sell_price' => $this->money($row['SellPrice']),
                'buy_price' => $this->money($row['BuyPrice']),
                'source_price_list_id' => null,
                'source_factor' => null,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $report['prices_written'] = count($batch);

        if ($dryRun) {
            return $report + ['dry_run' => true];
        }

        $table = (new ProductPrice)->getTable();

        DB::transaction(function () use ($batch, $table, $log): void {
            foreach (array_chunk($batch, 1000) as $i => $chunk) {
                DB::table($table)->upsert(
                    $chunk,
                    ['price_list_id', 'product_id', 'unit_key'],
                    ['sell_price', 'buy_price', 'source_price_list_id', 'source_factor', 'updated_at'],
                );
                $log('Prices written: '.min(($i + 1) * 1000, count($batch)));
            }
        });

        return $report + ['dry_run' => false];
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

    /**
     * @param  array<int, string>  $skus
     * @return array<string, int>
     */
    private function productIdsBySku(array $skus): array
    {
        $map = [];

        foreach (array_chunk($skus, 1000) as $chunk) {
            foreach (Product::query()->withTrashed()->whereIn('sku', $chunk)->pluck('id', 'sku') as $sku => $id) {
                $map[(string) $sku] = (int) $id;
            }
        }

        return $map;
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 4, '.', '') : '0.0000';
    }
}
