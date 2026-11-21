<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Items\Support\Itqan\CategoryMapEntry;
use Modules\Items\Support\Itqan\CategoryMapFile;
use Modules\Items\Support\Itqan\CategoryMapProposer;
use Modules\Items\Support\Itqan\ItqanCategoryNodes;
use Throwable;

/**
 * يكتب مقترح ربط تصنيفات إتقان (الماركة واللون لكل تصنيف) في CSV يراجعه صاحب
 * النظام قبل النقل الكامل. لا يكتب فوق ملف موجود دون --force، لانه قد يكون عُدّل.
 */
class ItqanCategoryMap extends Command
{
    protected $signature = 'itqan:category-map
        {--path= : Itqan data folder (almamoun2015). Defaults to ITQAN_DATA_PATH}
        {--output= : CSV path. Defaults to storage/app/itqan/category-map.csv}
        {--force : Overwrite an existing file}';

    protected $description = 'Propose how each Itqan category maps to a brand category and a finish (colour), as a CSV to review.';

    public function handle(CategoryMapProposer $proposer): int
    {
        $output = $this->option('output') ?: CategoryMapFile::defaultPath();

        if (is_file($output) && ! $this->option('force')) {
            $this->error("{$output} already exists (it may have been edited). Use --force to overwrite.");

            return self::FAILURE;
        }

        try {
            $source = ItqanSource::fromConfig($this->option('path'));
            $this->line('Reading Itqan items ('.$source->count('items').' rows)...');
            $nodes = ItqanCategoryNodes::fromRows($source->rows('items', ['ItemId', 'ItemString', 'ItemParent', 'ItemType', 'isActive']));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $entries = $proposer->propose(array_values($nodes));
        CategoryMapFile::write($output, $entries);

        $kept = array_filter($entries, fn (CategoryMapEntry $entry): bool => $entry->kept());
        $this->table(['Key', 'Value'], [
            ['category_nodes', count($entries)],
            ['keep', count($kept)],
            ['skip', count($entries) - count($kept)],
            ['unknown_brand', count(array_filter($entries, fn (CategoryMapEntry $entry): bool => $entry->note === 'brand?'))],
            ['new_categories', count(array_unique(array_map(fn (CategoryMapEntry $entry): string => $entry->categoryPath, $kept)))],
            ['finishes', count(array_unique(array_filter(array_map(fn (CategoryMapEntry $entry): ?string => $entry->finish, $kept))))],
            ['file', $output],
        ]);

        return self::SUCCESS;
    }
}
