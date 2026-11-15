<?php

namespace Modules\Accounting\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Repositories\Contracts\PaymentAllocationRepositoryInterface;
use Modules\Accounting\Services\PaymentAllocationService;

/**
 * بيانات لوحة تخصيص الدفعات (Vue: Pages/parts/PaymentAllocationPanel.vue) لصفحة المستند:
 *
 * - السند او المرتجع: المخصَّص وغير المخصَّص، وتخصيصاته، وفواتير الطرف المفتوحة للتخصيص.
 * - الفاتورة: المسدَّد والمتبقي، وما سُدِّد منها (للعرض فقط).
 *
 * صفحة المستند ترسلها خاصية: `'allocationPanel' => PaymentAllocationPanel::of($record)`.
 */
final class PaymentAllocationPanel
{
    public function __construct(
        private readonly PaymentAllocationService $allocations,
        private readonly PaymentAllocationRepositoryInterface $repository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function of(Model $record): array
    {
        return app(self::class)->build($record);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Model $record): array
    {
        $record->refresh();
        $isInvoice = $record instanceof SalesInvoice || $record instanceof PurchaseInvoice;
        $canAllocate = ! $isInvoice && $record->isPosted();
        $unallocated = $isInvoice ? 0.0 : $this->allocations->unallocatedAmount($record);
        $allocations = $isInvoice ? $this->allocations->allocationsFor($record) : $this->allocations->allocationsOf($record);

        return [
            'is_invoice' => $isInvoice,
            'can_allocate' => $canAllocate,
            'source' => $isInvoice ? null : ['type' => $this->repository->sourceTypeOf($record), 'id' => $record->getKey()],
            'paid' => $isInvoice ? Number::money($record->paid_amount) : null,
            'outstanding' => $isInvoice ? Number::money($record->outstanding_amount) : null,
            'allocated' => $isInvoice ? null : Number::money($record->allocated_amount),
            'unallocated' => Number::money($unallocated),
            'has_unallocated' => $unallocated > 0,
            'allocations' => $allocations->map(function (PaymentAllocation $allocation) use ($isInvoice) {
                $document = $isInvoice ? $allocation->source : $allocation->invoice;
                $difference = (float) $allocation->exchange_difference_base;

                return [
                    'id' => $allocation->id,
                    'document' => $document?->reference_no,
                    'date' => $allocation->allocation_date?->format('d/m/Y'),
                    'amount' => Number::money($allocation->amount),
                    'exchange_difference' => $difference != 0 ? Number::money($difference) : null,
                ];
            })->values()->all(),
            'open_invoices' => $canAllocate && $unallocated > 0
                ? $this->allocations->openInvoices($record)->map(fn (Model $invoice) => [
                    'id' => $invoice->getKey(),
                    'reference_no' => $invoice->reference_no,
                    'invoice_date' => $invoice->invoice_date?->format('d/m/Y'),
                    'due_date' => $invoice->due_date?->format('d/m/Y'),
                    'total' => Number::money($invoice->total_amount),
                    'outstanding' => Number::money($invoice->outstanding_amount),
                    // المبلغ المقترح (الافتراضي عند ترك الحقل فارغاً).
                    'suggested' => Number::money(min($unallocated, (float) $invoice->outstanding_amount)),
                ])->values()->all()
                : [],
        ];
    }
}
