<?php

namespace Modules\Items\Entities\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;
use Modules\Items\Support\Search\ItemsSearchIndex;

/**
 * صنف او تصنيف في محرك البحث (Scout + Meilisearch).
 *
 * مستنده يُبنى من عدة جداول (الترجمات، التصنيفات، الباركودات، العلامة، المصنّع)، فلا يُرسل
 * عند حفظ السجل نفسه (قبل حفظ ترجماته وتصنيفاته)، بل يرسله ItemsSearchIndex في آخر الطلب
 * مع فهرس قاعدة البيانات، من قراءة واحدة. الحذف والاسترجاع يمرّان بـ Scout كالعادة.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait IndexedByItemsSearch
{
    use Searchable;

    public function searchIndexShouldBeUpdated(): bool
    {
        return false;
    }

    public function makeSearchableUsing(Collection $models): Collection
    {
        app(ItemsSearchIndex::class)->prime(static::class, $models->modelKeys());

        return $models;
    }

    public function toSearchableArray(): array
    {
        return app(ItemsSearchIndex::class)->engineDocument(static::class, (int) $this->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function searchIndexSettings(): array
    {
        return ItemsSearchIndex::indexSettings(static::class);
    }

    /**
     * بناء الفهرس كله دفعة دفعة دون تحميل الموديلات (search:sync، scout:import).
     *
     * @param  (Closure(int): void)|null  $progress
     */
    public static function rebuildSearchIndex(?Closure $progress = null, int $chunk = 500): int
    {
        return app(ItemsSearchIndex::class)->rebuildEngine(static::class, $progress, $chunk);
    }

    public static function makeAllSearchable($chunk = null)
    {
        static::rebuildSearchIndex(chunk: (int) ($chunk ?: config('scout.chunk.searchable', 500)));
    }
}
