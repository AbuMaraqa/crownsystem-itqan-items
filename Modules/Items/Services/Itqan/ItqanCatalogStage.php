<?php

namespace Modules\Items\Services\Itqan;

use Modules\Core\Support\Itqan\ItqanImportStage;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Support\Itqan\CatalogSample;
use Modules\Items\Support\Itqan\CategoryMapFile;
use Modules\Items\Support\Itqan\CategoryMapProposer;
use Modules\Items\Support\Itqan\ItqanCategoryNodes;
use Modules\Items\Support\Search\ItemsSearchIndex;

/**
 * مرحلة الاصناف بالشكل الجديد: التصنيف هو الماركة، والمقطع صنف مرة واحدة، والألوان
 * أبناء، وسعر الكيلو على (التصنيف × اللون). تأخذ مكان المرحلة القديمة items.
 *
 * - الربط من storage/app/itqan/category-map.csv بعد مراجعة صاحب النظام، والا يُقترح في الذاكرة.
 * - الأبناء للأوراق المستخدمة في فواتير إتقان (invoicesdet) فقط؛ --sample و--all-finishes تنشئ كل الألوان.
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.
 */
final class ItqanCatalogStage implements ItqanImportStage
{
    public function __construct(
        private readonly CatalogImporter $importer,
        private readonly CategoryMapProposer $proposer,
    ) {}

    public function key(): string
    {
        return 'items';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function run(ItqanSource $source, bool $dryRun, callable $log): array
    {
        ini_set('memory_limit', (string) env('ITEMS_SEED_MEMORY_LIMIT', '2048M'));

        $log('Reading Itqan items ('.$source->count('items').' rows)...');
        $rows = [];
        foreach ($source->rows('items', LegacyItemsImporter::LEGACY_COLUMNS) as $row) {
            $rows[] = $row;
        }

        $map = $this->map($rows, $log);
        $nodes = ItqanCategoryNodes::fromRows($rows);
        $leafParents = [];
        foreach ($rows as $row) {
            $id = trim((string) $row['ItemId']);
            if ($id !== CategoryMapProposer::TREE_ROOT && ! isset($nodes[$id])) {
                $leafParents[] = trim((string) $row['ItemParent']);
            }
        }
        CategoryMapFile::assertMatches($map, $nodes, $leafParents);

        $sample = (bool) config('core.itqan.sample', false);
        $this->importer->withCodeFloor(self::maxRootCode($map));

        if ($sample) {
            $this->importer->asPartial();
            $rows = CatalogSample::select($rows);
            $map = CatalogSample::restrictMap($map, $rows);
            $log('Sample: '.count($rows).' rows, '.count($map).' category nodes.');
        }

        $used = [];
        foreach ($source->rows('invoicesdet', ['theItemId']) as $row) {
            $used[trim((string) $row['theItemId'])] = true;
        }

        $report = $this->importer->import($rows, $map, $used, $sample || (bool) config('core.itqan.all_finishes', false), $dryRun, $log);

        if (! $dryRun) {
            $log('Rebuilding the items search index...');
            $report['search_indexed'] = app(ItemsSearchIndex::class)->rebuild();
        }

        return $report + ['sample' => $sample];
    }

    /**
     * اكبر رقم جذر (خمس خانات) في ملف الربط كاملاً، قبل تقييده بالعيّنة (R-12).
     *
     * @param  array<string, \Modules\Items\Support\Itqan\CategoryMapEntry>  $map
     */
    private static function maxRootCode(array $map): int
    {
        $max = 0;
        foreach (array_keys($map) as $code) {
            $code = (string) $code;
            if (strlen($code) === 5 && ctype_digit($code)) {
                $max = max($max, (int) $code);
            }
        }

        return $max;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, \Modules\Items\Support\Itqan\CategoryMapEntry>
     */
    private function map(array $rows, callable $log): array
    {
        $path = CategoryMapFile::defaultPath();

        if (is_file($path)) {
            $log('Category map: '.$path);

            return CategoryMapFile::read($path);
        }

        $log('Category map file not found; using the proposed map. Run itqan:category-map to review it first.');

        return $this->proposer->propose(array_values(ItqanCategoryNodes::fromRows($rows)));
    }
}
