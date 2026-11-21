<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * يحفظ ترتيب الصفوف بعد السحب والإفلات في الجدول. الواجهة ترسل معرّفات صفوف الصفحة المعروضة بترتيبها
 * الجديد، فنعيد توزيع مواضعها الحالية نفسها عليها: يبقى ترتيب باقي السجلات (الصفحات الأخرى أو غير
 * المطابقة للتصفية) كما هو. التحديث مباشر على الجدول فلا يُسجَّل نشاط لكل سحب.
 */
final class RecordOrderService
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<int|string>  $ids  صفوف الصفحة بترتيبها الجديد
     */
    public function reorder(string $model, array $ids): void
    {
        /** @var Model $instance */
        $instance = new $model;
        $column = method_exists($instance, 'sortOrderColumn') ? $instance->sortOrderColumn() : 'sort_order';
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($instance, $column, $ids): void {
            $positions = $instance->newQuery()
                ->whereKey($ids)
                ->lockForUpdate()
                ->orderBy($column)
                ->orderBy($instance->getKeyName())
                ->pluck($column)
                ->map(fn ($position) => (int) $position)
                ->values()
                ->all();

            if (count($positions) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => __('Some rows no longer exist. Refresh the page and try again.')]);
            }

            // Equal positions (rows never ordered) become consecutive, so the new order holds.
            foreach ($positions as $index => $position) {
                if ($index > 0 && $position <= $positions[$index - 1]) {
                    $positions[$index] = $positions[$index - 1] + 1;
                }
            }

            foreach ($ids as $index => $id) {
                $instance->newQuery()->whereKey($id)->update([$column => $positions[$index]]);
            }
        });
    }
}
