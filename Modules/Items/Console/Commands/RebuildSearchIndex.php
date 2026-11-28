<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;

class RebuildSearchIndex extends Command
{
    protected $signature = 'items:search-index
        {--chunk=500 : Number of items indexed per batch}';

    protected $description = 'Rebuild the items and categories search index (items_product_search, items_categories.search_text) and send the same documents to the search engine (Meilisearch) when it is enabled. Run it after a bulk import or a direct database change.';

    public function handle(ItemsSearchIndex $index): int
    {
        SearchCapabilities::forget();

        if (! SearchCapabilities::hasProductIndex()) {
            $this->error('The search index table does not exist yet. Run php artisan migrate first.');

            return self::FAILURE;
        }

        $total = DB::table(config('items.table_prefix').'products')->count();
        $bar = $this->output->createProgressBar($total);
        $started = microtime(true);

        $count = $index->rebuild(
            fn (int $done) => $bar->setProgress(min($done, $total)),
            max((int) $this->option('chunk'), 50),
        );

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Indexed %d items in %.1f seconds.', $count, microtime(true) - $started));

        if (! SearchCapabilities::hasFullText()) {
            $this->warn('No FULLTEXT index on the search table: search will fall back to LIKE, which is slower on large catalogues.');
        }

        return self::SUCCESS;
    }
}
