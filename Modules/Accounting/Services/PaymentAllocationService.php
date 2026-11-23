<?php

namespace Modules\Accounting\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;

/**
 * تخصيص الدفعات على الفواتير (Payment Reconciliation):
 *
 * - المصدر: سند قبض او مرتجع مبيعات → فاتورة بيع؛ سند صرف او مرتجع مشتريات → فاتورة شراء.
 * - المبلغ بعملة الفاتورة، ولا يتجاوز المتبقي على الفاتورة ولا غير المخصَّص من المصدر.
 * - فرق سعر الصرف بين الفاتورة والمصدر (بالعملة الاساسية) يُقيَّد على حساب الطرف مقابل
 *   «أرباح فروقات العملة» او «خسائر فروقات العملة»، فيصبح رصيد الطرف صفراً عند السداد التام.
 * - المسدَّد والمتبقي على الفاتورة، والمخصَّص من المصدر، يُعاد حسابها بعد كل تغيير.
 */
class PaymentAllocationService
{
    private const EPSILON = 0.00005;

    private const INVOICE_FOR_SOURCE = [
        ReceiptVoucher::class => SalesInvoice::class,
        SalesReturn::class => SalesInvoice::class,
        PaymentVoucher::class => PurchaseInvoice::class,
        PurchaseReturn::class => PurchaseInvoice::class,
    ];

    public function allocate(Model $source, Model $invoice, float|string $amount, ?string $date = null): PaymentAllocation
    {
        $amount = round((float) str_replace([',', ' '], '', (string) $amount), 4);

        if ($amount <= 0) {
            throw new DomainException(__('Allocated amount must be greater than zero.'));
        }

        return DB::transaction(function () use ($source, $invoice, $amount, $date) {
            $source = $source->newQuery()->lockForUpdate()->findOrFail($source->getKey());
            $invoice = $invoice->newQuery()->lockForUpdate()->findOrFail($invoice->getKey());

            $this->assertCompatible($source, $invoice);

            if ($amount - $this->outstanding($invoice) > self::EPSILON) {
                throw new DomainException(__('Allocated amount exceeds the outstanding amount of invoice :reference.', ['reference' => $invoice->reference_no]));
            }

            if ($amount - $this->unallocatedAmount($source) > self::EPSILON) {
                throw new DomainException(__('Allocated amount exceeds the unallocated amount of :reference.', ['reference' => $source->reference_no]));
            }

            $date = $date ?: max($this->documentDate($source), $this->documentDate($invoice));

            $allocation = PaymentAllocation::query()->create([
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'invoice_type' => $invoice->getMorphClass(),
                'invoice_id' => $invoice->getKey(),
                'client_id' => $invoice->client_id,
                'currency_id' => $invoice->currency_id,
                'allocation_date' => $date,
                'amount' => $amount,
                'invoice_exchange_rate' => (float) $invoice->exchange_rate ?: 1,
                'source_exchange_rate' => (float) $source->exchange_rate ?: 1,
                'created_by' => auth()->id(),
            ]);

            $this->postExchangeDifference($allocation, $invoice);
            $this->refreshInvoice($invoice);
            $this->refreshSource($source);

            return $allocation->refresh();
        });
    }

    /**
     * تخصيص من لوحة التخصيص: الفاتورة يجب ان تكون ما زالت ضمن فواتير الطرف المفتوحة، والمبلغ
     * الفارغ يعني اقل الاثنين: غير المخصَّص من المصدر والمتبقي على الفاتورة.
     */
    public function allocateToOpenInvoice(Model $source, int $invoiceId, float|string|null $amount = null): PaymentAllocation
    {
        $invoice = $this->openInvoices($source)->firstWhere('id', $invoiceId);

        if (! $invoice) {
            throw new DomainException(__('The invoice is no longer open.'));
        }

        $amount = filled($amount) ? $amount : min($this->unallocatedAmount($source), $this->outstanding($invoice));

        return $this->allocate($source, $invoice, $amount);
    }

    /**
     * يخصص غير المخصَّص من المصدر على فواتير الطرف المفتوحة، الاقدم اولاً (FIFO).
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function allocateAutomatically(Model $source): Collection
    {
        return DB::transaction(function () use ($source) {
            $allocations = collect();

            foreach ($this->openInvoices($source) as $invoice) {
                $remaining = $this->unallocatedAmount($source->refresh());

                if ($remaining <= self::EPSILON) {
                    break;
                }

                $allocations->push($this->allocate($source, $invoice, min($remaining, $this->outstanding($invoice))));
            }

            return $allocations;
        });
    }

    public function deallocate(PaymentAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation) {
            $allocation = PaymentAllocation::query()->with(['source', 'invoice', 'journalEntry'])->lockForUpdate()->findOrFail($allocation->getKey());

            if ($allocation->journalEntry && $allocation->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($allocation->journalEntry, __('Allocation removed'));
            }

            $allocation->delete();

            if ($allocation->invoice) {
                $this->refreshInvoice($allocation->invoice);
            }

            if ($allocation->source) {
                $this->refreshSource($allocation->source);
            }
        });
    }

    /**
     * يُستدعى عند الغاء السند او المرتجع: تُلغى كل تخصيصاته فتعود الفواتير مفتوحة.
     */
    public function deallocateAll(Model $source): void
    {
        $this->allocationsOf($source)->each(fn (PaymentAllocation $allocation) => $this->deallocate($allocation));
    }

    /**
     * الفاتورة المسدَّد منها شيء لا تُلغى حتى تُفك تخصيصاتها (كما في ERPNext).
     */
    public function assertInvoiceHasNoAllocations(Model $invoice): void
    {
        $exists = PaymentAllocation::query()
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('invoice_id', $invoice->getKey())
            ->exists();

        if ($exists) {
            throw new DomainException(__('Remove the payments allocated to this invoice before cancelling it.'));
        }
    }

    public function refreshInvoice(Model $invoice): void
    {
        $paid = $invoice->isPosted() ? $this->allocatedTo($invoice) : 0.0;
        $outstanding = $invoice->isPosted() ? max(round((float) $invoice->total_amount - $paid, 4), 0) : 0.0;

        $invoice->forceFill(['paid_amount' => $paid, 'outstanding_amount' => $outstanding])->saveQuietly();
    }

    public function refreshSource(Model $source): void
    {
        $allocated = round((float) PaymentAllocation::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->sum('amount'), 4);

        $source->forceFill(['allocated_amount' => $allocated])->saveQuietly();
    }

    public function unallocatedAmount(Model $source): float
    {
        if (! $source->isPosted()) {
            return 0.0;
        }

        $allocated = (float) PaymentAllocation::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->sum('amount');

        return max(round($this->sourceAmount($source) - $allocated, 4), 0);
    }

    public function outstanding(Model $invoice): float
    {
        if (! $invoice->isPosted()) {
            return 0.0;
        }

        return max(round((float) $invoice->total_amount - $this->allocatedTo($invoice), 4), 0);
    }

    /**
     * فواتير الطرف المرحّلة التي عليها متبقٍ وبنفس عملة المصدر، الاقدم اولاً.
     *
     * @return Collection<int, Model>
     */
    public function openInvoices(Model $source): Collection
    {
        $invoiceClass = $this->invoiceClass($source);

        return $invoiceClass::query()
            ->where('client_id', $source->client_id)
            ->where('currency_id', $source->currency_id)
            ->where('status', $invoiceClass === SalesInvoice::class ? SalesInvoiceStatus::POSTED->value : PurchaseInvoiceStatus::POSTED->value)
            ->where('outstanding_amount', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PaymentAllocation>
     */
    public function allocationsOf(Model $source): Collection
    {
        return PaymentAllocation::query()
            ->with('invoice')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PaymentAllocation>
     */
    public function allocationsFor(Model $invoice): Collection
    {
        return PaymentAllocation::query()
            ->with('source')
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('invoice_id', $invoice->getKey())
            ->orderBy('id')
            ->get();
    }

    public function supports(Model $source): bool
    {
        return isset(self::INVOICE_FOR_SOURCE[$source::class]);
    }

    private function assertCompatible(Model $source, Model $invoice): void
    {
        if ($this->invoiceClass($source) !== $invoice::class) {
            throw new DomainException(__('This document cannot be allocated to this invoice type.'));
        }

        if (! $source->isPosted() || ! $invoice->isPosted()) {
            throw new DomainException(__('Only posted documents can be allocated.'));
        }

        if ((int) $source->client_id !== (int) $invoice->client_id) {
            throw new DomainException(__('The document and the invoice must belong to the same party.'));
        }

        if ((int) $source->currency_id !== (int) $invoice->currency_id) {
            throw new DomainException(__('The document and the invoice must have the same currency.'));
        }
    }

    /**
     * فرق العملة = المبلغ × (سعر المصدر − سعر الفاتورة) بالعملة الاساسية.
     */
    private function postExchangeDifference(PaymentAllocation $allocation, Model $invoice): void
    {
        $amount = (float) $allocation->amount;
        $difference = round(round($amount * (float) $allocation->source_exchange_rate, 4) - round($amount * (float) $allocation->invoice_exchange_rate, 4), 4);

        if (abs($difference) < self::EPSILON) {
            return;
        }

        $isSales = $invoice instanceof SalesInvoice;
        $parties = app(PartyAccounts::class);
        $partyAccountId = $isSales ? $parties->customerAccountId($invoice->client_id) : $parties->supplierAccountId($invoice->client_id);
        $ledger = app(LedgerAccounts::class);

        // ذمم مدينة: سعر تحصيل اعلى = ربح. ذمم دائنة: سعر سداد اعلى = خسارة.
        $isGain = $isSales ? $difference > 0 : $difference < 0;
        $value = abs($difference);
        $exchangeAccountId = $ledger->mapped($isGain ? LedgerAccounts::EXCHANGE_GAIN_KEY : LedgerAccounts::EXCHANGE_LOSS_KEY);
        // الربح يُقيَّد مديناً على الطرف والخسارة دائناً، في الحالتين.
        $partyIsDebit = $isGain;

        $description = __('Exchange difference').' '.$invoice->reference_no;
        $baseCurrencyId = app(CurrencyExchangeRateService::class)->baseCurrencyId();

        $line = fn (int $accountId, bool $debit) => [
            'account_id' => $accountId,
            'branch_id' => $invoice->branch_id,
            'currency_id' => $baseCurrencyId,
            'exchange_rate' => 1,
            'debit' => $debit ? $value : 0,
            'credit' => $debit ? 0 : $value,
            'description' => $description,
        ];

        $service = app(JournalEntryService::class);
        $entry = $service->createDraft([
            // تاريخ التسوية، او اليوم ان كان شهرها مقفلاً.
            'entry_date' => $service->postableDate($allocation->allocation_date->toDateString()),
            'reference_no' => $invoice->reference_no,
            'source_module' => 'Accounting',
            'source_type' => 'ExchangeDifference',
            'sourceable_id' => $allocation->id,
            'sourceable_type' => PaymentAllocation::class,
            'description' => $description,
        ], [
            $line($partyAccountId, $partyIsDebit),
            $line($exchangeAccountId, ! $partyIsDebit),
        ]);

        $service->post($entry);

        $allocation->forceFill([
            'exchange_difference_base' => $isGain ? $value : -$value,
            'journal_entry_id' => $entry->id,
        ])->saveQuietly();
    }

    private function allocatedTo(Model $invoice): float
    {
        return round((float) PaymentAllocation::query()
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('invoice_id', $invoice->getKey())
            ->sum('amount'), 4);
    }

    private function sourceAmount(Model $source): float
    {
        return round((float) ($source instanceof ReceiptVoucher || $source instanceof PaymentVoucher ? $source->amount : $source->total_amount), 4);
    }

    private function documentDate(Model $document): string
    {
        $date = $document->voucher_date ?? $document->return_date ?? $document->invoice_date;

        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
    }

    private function invoiceClass(Model $source): string
    {
        return self::INVOICE_FOR_SOURCE[$source::class]
            ?? throw new DomainException(__('This document cannot be allocated to this invoice type.'));
    }
}
