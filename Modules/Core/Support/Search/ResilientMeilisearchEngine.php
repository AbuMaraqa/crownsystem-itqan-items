<?php

namespace Modules\Core\Support\Search;

use Laravel\Scout\Engines\MeilisearchEngine;
use Throwable;

/**
 * محرك Meilisearch كما في Scout، الا ان تحديث الفهرس لا يُفشل العملية التي اطلقته:
 * اذا توقف الخادم يُسجَّل الخطأ (SearchIndex::failed) ويُعلَّم فهرس الجدول قديماً،
 * ويكمل حفظ السجل. وما دام المحرك متوقفاً لا يُنتظر الخادم في كل حفظ.
 */
class ResilientMeilisearchEngine extends MeilisearchEngine
{
    public function update($models)
    {
        $this->safely($models, fn () => parent::update($models));
    }

    public function delete($models)
    {
        $this->safely($models, fn () => parent::delete($models));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    private function safely($models, callable $write): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first()::class;

        if (! SearchIndex::enabled()) {
            SearchIndex::markStale($model);

            return;
        }

        try {
            $write();
        } catch (Throwable $exception) {
            SearchIndex::failed($exception, $model);
        }
    }
}
