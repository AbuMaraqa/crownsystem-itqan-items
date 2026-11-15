<?php

namespace Modules\Accounting\Observers;

use Modules\Accounting\Entities\StockLimitChange;
use Modules\Accounting\Services\StockAlertService;
use Modules\Accounting\Support\StockLimits;
use Modules\Items\Entities\Product;

/**
 * حدود الصنف من اي نموذج (بطاقة الشجرة، تعريف الصنف، متغيراته، قسم المتابعة):
 * - من لا يملك «تعديل الحدود» لا تتغير الحدود باسمه، حتى لو وصل الطلب للخادم مباشرة.
 * - كل تعديل يُسجَّل (من، متى، القديم والجديد)، ثم تُحسب حالة الصنف فوراً (قد يدخل المتابعة او يخرج منها).
 *
 * العمليات بلا مستخدم (استيراد، اوامر الطرفية) لا تُقيَّد بالصلاحية.
 */
class StockLimitObserver
{
    public function updating(Product $product): void
    {
        if (! auth()->check() || StockLimits::canEdit()) {
            return;
        }

        foreach (StockLimits::FIELDS as $field) {
            $product->setAttribute($field, $product->getOriginal($field));
        }
    }

    public function created(Product $product): void
    {
        if (StockLimits::isMonitored($product)) {
            $this->record($product, [], $product->only(StockLimits::FIELDS));
        }
    }

    public function updated(Product $product): void
    {
        $old = array_intersect_key($product->getPrevious(), array_flip(StockLimits::FIELDS)) + $product->only(StockLimits::FIELDS);

        if (StockLimits::changed($old, $product->only(StockLimits::FIELDS))) {
            $this->record($product, $old, $product->only(StockLimits::FIELDS));
        }
    }

    private function record(Product $product, array $old, array $new): void
    {
        StockLimitChange::query()->create([
            'product_id' => $product->id,
            'old_max_limit' => $old['max_limit'] ?? null,
            'old_min_limit_1' => $old['min_limit_1'] ?? null,
            'old_min_limit_2' => $old['min_limit_2'] ?? null,
            'new_max_limit' => $new['max_limit'] ?? null,
            'new_min_limit_1' => $new['min_limit_1'] ?? null,
            'new_min_limit_2' => $new['min_limit_2'] ?? null,
            'changed_by' => auth()->id(),
        ]);

        app(StockAlertService::class)->evaluate($product->id);
    }
}
