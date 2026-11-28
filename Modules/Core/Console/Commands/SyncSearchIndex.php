<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Scout\Engines\MeilisearchEngine;
use Modules\Core\Support\Search\SearchIndex;
use Throwable;

/**
 * بناء فهارس محرك البحث (Meilisearch) واعداداتها لكل الجداول المسجلة في SearchIndex.
 *
 *  php artisan search:sync                 الاعدادات ثم كل الجداول
 *  php artisan search:sync --fresh         حذف الفهارس واعادة بنائها
 *  php artisan search:sync --stale         ما فاته تحديث وقت توقف الخادم فقط (مجدول كل 10 دقائق)
 *  php artisan search:sync --settings      الاعدادات فقط (بعد تغيير اعمدة البحث في موديل)
 *  php artisan search:sync "Modules\Items\Entities\Product"
 */
class SyncSearchIndex extends Command
{
    protected $signature = 'search:sync
        {models?* : Model classes to index (default: every registered model)}
        {--fresh : Delete each index and build it again}
        {--stale : Only the indexes that missed updates while the search server was down}
        {--settings : Only push the index settings}
        {--chunk=500 : Records sent per batch}';

    protected $description = 'Build the Meilisearch indexes (Laravel Scout) of the searchable tables and push their settings.';

    public function handle(): int
    {
        if (! SearchIndex::configured()) {
            $this->warn('Search engine disabled (SCOUT_DRIVER is null): searches use the database.');

            return self::SUCCESS;
        }

        $models = $this->models();

        if ($models === []) {
            $this->info($this->option('stale') ? 'No stale search index.' : 'No searchable model registered.');

            return self::SUCCESS;
        }

        SearchIndex::reset();

        if (! SearchIndex::healthy()) {
            $this->error('The search server ('.config('scout.meilisearch.host').') does not answer. Searches fall back to the database until it is up.');

            return self::FAILURE;
        }

        $settings = SearchIndex::settings();
        $engine = SearchIndex::engine();

        foreach ($models as $class) {
            $index = (new $class)->searchableAs();

            try {
                if ($engine instanceof MeilisearchEngine) {
                    if ($this->option('fresh')) {
                        rescue(fn () => $engine->deleteIndex($index), report: false);
                    }

                    $engine->updateIndexSettings($index, $settings[$index] ?? []);
                }

                if ($this->option('settings')) {
                    $this->line("<info>✔</info> {$index}: settings");

                    continue;
                }

                $count = $this->import($class);
            } catch (Throwable $exception) {
                $this->error("{$index}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            if (! SearchIndex::enabled()) {
                $this->error("{$index}: the search server stopped answering; run the command again when it is up.");

                return self::FAILURE;
            }

            SearchIndex::clearStale($class);
            $this->line("<info>✔</info> {$index}: {$count} records");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $registered = SearchIndex::models();
        $requested = $this->argument('models');

        if ($requested !== []) {
            $unknown = array_diff($requested, $registered);

            foreach ($unknown as $class) {
                $this->warn("Not a registered searchable model: {$class}");
            }

            return array_values(array_intersect($registered, $requested));
        }

        return $this->option('stale') ? array_values(array_intersect($registered, SearchIndex::stale())) : $registered;
    }

    /**
     * كتابة سجلات الجدول في فهرسه. جدول له بانٍ خاص (rebuildSearchIndex، كالاصناف التي
     * يُبنى مستندها من عدة جداول) يُبنى به، والباقي دفعة دفعة بـ Scout.
     *
     * @param  class-string<Model>  $class
     */
    private function import(string $class): int
    {
        $chunk = max((int) $this->option('chunk'), 50);
        $bar = $this->output->createProgressBar();

        if (method_exists($class, 'rebuildSearchIndex')) {
            $count = $class::rebuildSearchIndex(fn (int $done) => $bar->setProgress($done), $chunk);
        } else {
            $count = 0;

            $class::makeAllSearchableQuery()->chunkById($chunk, function (Collection $models) use (&$count, $bar): bool {
                $models->searchableSync();
                $count += $models->count();
                $bar->setProgress($count);

                // توقف الخادم اثناء البناء: لا فائدة من ارسال الباقي.
                return SearchIndex::enabled();
            });
        }

        $bar->clear();

        return $count;
    }
}
