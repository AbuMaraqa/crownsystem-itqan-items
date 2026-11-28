<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory\Concerns;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Entities\User;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * ما تشترك فيه صفحات مستندات المخزن الاربعة (سند الاستلام، سند التسليم، التحويل، التسوية):
 * اعمدة القائمة وفلاترها (الحالة، المخزن، التاريخ)، رأس صفحة المستند (الحالة، القيد، من رحّل،
 * والاجراءات المسموحة)، وتحويل رفض الخدمة (DomainException) الى رسالة في الصفحة.
 */
trait InventoryDocumentPages
{
    /**
     * جدول القائمة: البحث في الرقم واعمدة المستند، فلاتر الحالة والمخزن والتاريخ والمنشئ،
     * ثم فلاتر المستند واعمدته الخاصة.
     *
     * @param  array<string, string>  $searchRelations  علاقة => عمود (رقم الطلبية، اسم الطرف)؛ اعمدة المستند نفسه من searchColumns() في موديله
     * @param  string|list<string>  $warehouseColumns  عمود المخزن، او عمودا المرسل والمستلم في التحويل
     * @param  list<AllowedFilter>  $filters
     * @param  list<string>  $sorts
     * @param  Closure(Model): array<string, mixed>  $row  اعمدة المستند الخاصة
     * @return array<string, mixed>
     */
    protected function documentTable(
        Builder $query,
        string $dateColumn,
        array $searchRelations,
        string|array $warehouseColumns,
        array $filters,
        array $sorts,
        Closure $row,
    ): array {
        $canSeeCost = (bool) auth()->user()?->can('Stock Value View');

        $relationColumns = array_map(fn (string $relation, string $column): string => "{$relation}.{$column}", array_keys($searchRelations), $searchRelations);

        return AdminTable::for($query)
            ->search($relationColumns, translated: null)
            ->filters(
                AdminFilter::text('reference_no'),
                AdminFilter::exact('status'),
                AllowedFilter::callback('warehouse', function (Builder $query, mixed $value) use ($warehouseColumns): void {
                    $query->where(function (Builder $query) use ($warehouseColumns, $value): void {
                        foreach ((array) $warehouseColumns as $column) {
                            $query->orWhere($column, (int) $value);
                        }
                    });
                }),
                AdminFilter::exact('created_by'),
                ...AdminFilter::dateRange($dateColumn),
                ...AdminFilter::numberRange('total_quantity'),
                ...$filters,
            )
            ->sorts('reference_no', $dateColumn, 'total_quantity', 'total_weight', 'total_cost', 'status', 'id', ...$sorts)
            ->defaultSort('-id')
            ->withCreator()
            ->rows(fn (Model $document) => [
                'id' => $document->id,
                'reference_no' => $document->reference_no,
                'date' => $document->{$dateColumn}?->format('d/m/Y'),
                'total_quantity' => Number::quantity($document->total_quantity),
                'total_weight' => Number::quantity($document->total_weight),
                'total_cost' => $canSeeCost ? number_format((float) $document->total_cost, 2) : null,
                'status' => StatusBadge::of($document->status),
                'editable' => $document->canBeEdited(),
                'can_delete' => $document->isDraft(),
                ...$row($document),
            ]);
    }

    /**
     * فلتر نصي على عمود علاقة (رقم الطلبية، اسم الطرف).
     */
    protected function relationText(string $name, string $relation, string $column): AllowedFilter
    {
        return AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($relation, $column): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas($relation, fn (Builder $related) => $related->whereLike($column, $term));
        });
    }

    /**
     * خيارات فلاتر القائمة المشتركة.
     *
     * @return array<string, mixed>
     */
    protected function tableOptions(): array
    {
        return [
            'statusOptions' => SelectOptions::from(InventoryDocumentStatus::options()),
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'userOptions' => SelectOptions::from(User::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all()),
            'canSeeCost' => (bool) auth()->user()?->can('Stock Value View'),
        ];
    }

    /**
     * رأس صفحة المستند: الرقم والحالة ومن رحّل، والقيد، وما يسمح به وضعه الآن (الصلاحيات
     * تُفحص في الصفحة بـ can() وعلى المسار).
     *
     * @return array<string, mixed>
     */
    protected function documentRecord(Model $document): array
    {
        $entry = $document->journalEntry;

        return [
            'id' => $document->id,
            'reference_no' => $document->reference_no,
            'status' => StatusBadge::of($document->status),
            'editable' => $document->canBeEdited(),
            'is_draft' => $document->isDraft(),
            'is_posted' => $document->isPosted(),
            'posted_by' => $document->postedBy?->name,
            'posted_at' => $document->posted_at?->format('d/m/Y H:i'),
            'created_by' => $document->createdBy?->name,
            'journal_entry' => $entry ? ['id' => $entry->id, 'number' => $entry->entry_number] : null,
            'total_cost' => auth()->user()?->can('Stock Value View') ? Number::money($document->total_cost) : null,
        ];
    }

    /**
     * ينفّذ اجراء الخدمة: نجاحه رسالة خضراء، ورفضه (DomainException) رسالة حمراء وكل سطر من
     * اسبابه (مثل نقص الرصيد لكل صنف) في `errors.document` لتعرضه الصفحة كاملاً.
     *
     * @param  callable(): mixed  $action
     * @param  Closure(mixed): RedirectResponse|null  $then  وجهة النجاح (الافتراضي: نفس الصفحة)
     */
    protected function attempt(callable $action, string $success, ?Closure $then = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (DomainException $exception) {
            return $this->refused($exception);
        }

        return ($then ? $then($result) : back())->with('success', $success);
    }

    protected function refused(DomainException $exception, ?RedirectResponse $to = null): RedirectResponse
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $exception->getMessage()))));

        return ($to ?? back())
            ->with('error', $lines[0] ?? $exception->getMessage())
            ->withErrors(['document' => implode("\n", $lines)]);
    }
}
