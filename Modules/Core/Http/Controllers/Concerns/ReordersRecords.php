<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\RecordOrderService;

/**
 * إجراء `reorder` لجدول يُرتَّب بالسحب والإفلات. المسار:
 * Route::post('/reorder', 'reorder')->name('reorder')->can('<Entity> Update');
 */
trait ReordersRecords
{
    /**
     * @return class-string<Model> نموذج يستخدم HasSortOrder
     */
    abstract protected function sortableModel(): string;

    public function reorder(Request $request, RecordOrderService $order): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $order->reorder($this->sortableModel(), $ids);

        return back()->with('success', __('Order saved'));
    }
}
