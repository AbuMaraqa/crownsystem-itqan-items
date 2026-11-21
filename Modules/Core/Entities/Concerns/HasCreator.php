<?php

namespace Modules\Core\Entities\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;

/**
 * من أضاف السجل (عمود created_by): يُملأ تلقائياً بالمستخدم الحالي عند الإنشاء إن لم يُحدَّد،
 * ويُعرض في عمود «أضيف بواسطة» عبر AdminTable::withCreator().
 *
 * @mixin Model
 */
trait HasCreator
{
    public static function bootHasCreator(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by') === null && auth()->id() !== null) {
                $model->setAttribute('created_by', auth()->id());
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
