<?php

namespace Modules\Core\Entities\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ترتيب يدوي للسجل (سحب وإفلات في الجدول): السجل الجديد يأخذ آخر موضع، والترتيب يتغيّر فقط عبر
 * RecordOrderService. يُستخدم مع AdminTable::reorderable() و ReordersRecords في المتحكّم.
 *
 * @mixin Model
 */
trait HasSortOrder
{
    public static function bootHasSortOrder(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->sortOrderColumn();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, (int) $model->newQuery()->max($column) + 1);
            }
        });
    }

    public function sortOrderColumn(): string
    {
        return 'sort_order';
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn($this->sortOrderColumn()))->orderBy($this->getQualifiedKeyName());
    }
}
