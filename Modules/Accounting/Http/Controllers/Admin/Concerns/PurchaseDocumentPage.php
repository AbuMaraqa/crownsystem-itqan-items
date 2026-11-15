<?php

namespace Modules\Accounting\Http\Controllers\Admin\Concerns;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Entities\User;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * ما تشترك فيه صفحات مستندات الشراء في لوحة Vue (عرض السعر، الطلبية، الفاتورة، المرتجع):
 * قوائم الاختيار، فلاتر القائمة المتكررة، وتحويل رفض قاعدة العمل (DomainException) الى رسالة.
 */
trait PurchaseDocumentPage
{
    /**
     * @return array<string, mixed>
     */
    protected function documentOptions(): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'taxOptions' => Tax::query()->active()->orderBy('name')->get()
                ->map(fn (Tax $tax) => ['value' => $tax->id, 'label' => $tax->name.' - '.number_format((float) $tax->rate, 2).'%', 'rate' => Number::clean($tax->rate)])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{value: int|string, label: string}>
     */
    protected function userOptions(): array
    {
        return SelectOptions::from(User::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all());
    }

    /**
     * الطرف بالاسم او الهاتف او البريد.
     */
    protected function partyFilter(string $name = 'client', string $relation = 'client'): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($relation): void {
            $term = $this->likeTerm($value);

            $query->whereHas($relation, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', $term)
                ->orWhereLike('phone', $term)
                ->orWhereLike('email', $term)));
        });
    }

    /**
     * مستندات فيها سطر يطابق عموده (الباركود او الوصف) النص المكتوب.
     */
    protected function itemsFilter(string $column): AllowedFilter
    {
        return AllowedFilter::callback("item_{$column}", function (Builder $query, mixed $value) use ($column): void {
            $query->whereHas('items', fn (Builder $items) => $items->whereLike($column, $this->likeTerm($value)));
        });
    }

    /**
     * مستندات مرتبطة بمستند آخر يطابق رقمه النص المكتوب.
     */
    protected function referenceFilter(string $name, string $relation): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($relation): void {
            $query->whereHas($relation, fn (Builder $query) => $query->whereLike('reference_no', $this->likeTerm($value)));
        });
    }

    protected function likeTerm(mixed $value): string
    {
        return '%'.addcslashes(trim((string) $value), '%_\\').'%';
    }

    /**
     * ينفذ اجراءً على المستند ويعيد الصفحة برسالة نجاحه، او برسالة قاعدة العمل التي رفضته.
     */
    protected function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }
}
