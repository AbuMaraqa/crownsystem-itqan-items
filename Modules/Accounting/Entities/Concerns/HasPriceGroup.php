<?php

namespace Modules\Accounting\Entities\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Items\Entities\CategoryFinish;

/**
 * سطر مستند قد يكون سطر مجموعة سعر (التصنيف × اللون) بلا صنف: سطر صنف الوزن الرئيسي في
 * المرحلة 2، وقطعه متغيّرات ذلك اللون. ItemsGrid يقرأ ويكتب العمود.
 */
trait HasPriceGroup
{
    public function initializeHasPriceGroup(): void
    {
        $this->mergeFillable(['category_finish_id']);
    }

    public function categoryFinish(): BelongsTo
    {
        return $this->belongsTo(CategoryFinish::class, 'category_finish_id');
    }

    public function isPriceGroupLine(): bool
    {
        return $this->category_finish_id !== null && $this->product_id === null;
    }

    /**
     * السطر مسعّر بالوزن: سطر مجموعة سعر، او صنف وزن.
     */
    public function isWeightPricedLine(): bool
    {
        return $this->isPriceGroupLine() || $this->product?->pricing_type === 'weight';
    }
}
