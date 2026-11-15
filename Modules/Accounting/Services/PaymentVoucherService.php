<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\ChequeDirection;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Core\Services\ReferenceNumberService;

class PaymentVoucherService
{
    private const SOURCE_MODULE = 'Accounting';

    public function create(array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($data) {
            $data = $this->normalizeData($data);
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'payment_voucher',
                $data['voucher_date'],
                ['prefix' => 'PMT']
            );
            $data['status'] = PaymentVoucherStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $voucher = PaymentVoucher::create($data);

            return $voucher->refresh()->load(['client', 'branch', 'account', 'currency']);
        });
    }

    public function update(PaymentVoucher $voucher, array $data): PaymentVoucher
    {
        if (! $voucher->canBeEdited()) {
            throw new DomainException(__('Only draft payment vouchers can be updated.'));
        }

        return DB::transaction(function () use ($voucher, $data) {
            $data = $this->normalizeData($data);
            unset($data['reference_no'], $data['created_by'], $data['journal_entry_id']);

            $voucher->update($data);

            return $voucher->refresh()->load(['client', 'branch', 'account', 'currency']);
        });
    }

    public function delete(PaymentVoucher $voucher): void
    {
        if (! $voucher->canBeEdited()) {
            throw new DomainException(__('Only draft payment vouchers can be deleted.'));
        }

        DB::transaction(function () use ($voucher) {
            $voucher->load('journalEntry.lines');

            if ($voucher->journalEntry) {
                $voucher->journalEntry->lines()->delete();
                $voucher->journalEntry->delete();
            }

            $voucher->delete();
        });
    }

    /**
     * زر الترحيل في صفحة السند يحفظ ما في النموذج اولاً ما دام السند مسودة، ثم يرحّله.
     */
    public function saveAndPost(PaymentVoucher $voucher, array $data): PaymentVoucher
    {
        if ($voucher->canBeEdited()) {
            $voucher = $this->update($voucher, $data);
        }

        return $this->post($voucher);
    }

    /**
     * الالغاء من السند ممكن ما دام السند مرحّلاً وليس له شيك قائم؛ الشيك القائم يُلغى
     * (او يُرتجع) من سجل الشيكات فيُلغى سنده معه.
     */
    public function canCancel(PaymentVoucher $voucher): bool
    {
        $cheque = $voucher->cheque;

        return $voucher->isPosted()
            && (! $cheque || in_array($cheque->status, [ChequeStatus::BOUNCED, ChequeStatus::CANCELLED], true));
    }

    public function post(PaymentVoucher $voucher): PaymentVoucher
    {
        if (! $voucher->canBePosted()) {
            throw new DomainException(__('Only draft payment vouchers can be posted.'));
        }

        return DB::transaction(function () use ($voucher) {
            $voucher = PaymentVoucher::query()
                ->with(['journalEntry', 'cheque'])
                ->lockForUpdate()
                ->findOrFail($voucher->id);

            // يُعاد الفحص بعد القفل: طلبان متزامنان يتجاوزان الفحص الاول معاً فيُرحَّل السند مرتين.
            if (! $voucher->canBePosted()) {
                throw new DomainException(__('Only draft payment vouchers can be posted.'));
            }

            if ((float) $voucher->amount <= 0) {
                throw new DomainException(__('Payment voucher amount must be greater than zero.'));
            }

            if ($voucher->payment_method === AccountingPaymentMethod::CHEQUE && blank($voucher->cheque_number)) {
                throw new DomainException(__('Cheque number is required for cheque payment vouchers.'));
            }

            $entry = $this->createJournalEntry($voucher);
            app(JournalEntryService::class)->post($entry);

            $voucher->update([
                'journal_entry_id' => $entry->id,
                'status' => PaymentVoucherStatus::POSTED->value(),
            ]);

            if ($voucher->payment_method === AccountingPaymentMethod::CHEQUE && ! $voucher->cheque) {
                Cheque::create([
                    'chequeable_type' => PaymentVoucher::class,
                    'chequeable_id' => $voucher->id,
                    'direction' => ChequeDirection::OUTGOING->value(),
                    'cheque_number' => $voucher->cheque_number,
                    'bank_name' => $voucher->cheque_bank_name,
                    'cheque_date' => $voucher->cheque_date,
                    'client_id' => $voucher->client_id,
                    'branch_id' => $voucher->branch_id,
                    'currency_id' => $voucher->currency_id,
                    'exchange_rate' => $voucher->exchange_rate,
                    'amount' => $voucher->amount,
                    'account_id' => $voucher->account_id,
                    'status' => ChequeStatus::UNDER_COLLECTION->value(),
                    'created_by' => $voucher->created_by ?? auth()->id(),
                ]);
            }

            return $voucher->refresh()->load(['client', 'branch', 'account', 'currency', 'journalEntry', 'cheque']);
        });
    }

    /**
     * الغاء سند صرف مرحّل بقيد عكسي، بعد فك تخصيصاته على الفواتير.
     * سند الشيك يُلغى من سجل الشيكات (الغاء او ارتجاع الشيك) ما دام الشيك قائماً؛
     * ChequeService يستدعيه بـ $fromCheque = true وقد يكون عكس القيد بنفسه.
     */
    public function cancel(PaymentVoucher $voucher, ?string $reason = null, bool $fromCheque = false): PaymentVoucher
    {
        return DB::transaction(function () use ($voucher, $reason, $fromCheque) {
            $voucher = PaymentVoucher::query()
                ->with(['journalEntry', 'cheque'])
                ->lockForUpdate()
                ->findOrFail($voucher->id);

            if (! $voucher->isPosted()) {
                throw new DomainException(__('Only posted payment vouchers can be cancelled.'));
            }

            $chequeIsOpen = $voucher->cheque
                && ! in_array($voucher->cheque->status, [ChequeStatus::BOUNCED, ChequeStatus::CANCELLED], true);

            if ($chequeIsOpen && ! $fromCheque) {
                throw new DomainException(__('Cancel or bounce the cheque from the cheques register first.'));
            }

            app(PaymentAllocationService::class)->deallocateAll($voucher);

            if ($voucher->journalEntry && $voucher->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($voucher->journalEntry, __('Cancellation of').' '.$voucher->reference_no);
            }

            $voucher->update([
                'status' => PaymentVoucherStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? $reason : null,
            ]);

            return $voucher->refresh()->load(['client', 'branch', 'account', 'currency', 'journalEntry', 'cheque']);
        });
    }

    private function normalizeData(array $data): array
    {
        $voucherDate = $data['voucher_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $voucherDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and voucher date.'));
        }

        $paymentMethod = AccountingPaymentMethod::tryFrom((int) ($data['payment_method'] ?? AccountingPaymentMethod::CASH->value()))
            ?? AccountingPaymentMethod::CASH;

        return [
            'client_id' => (int) $data['client_id'],
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null,
            'account_id' => (int) $data['account_id'],
            'voucher_date' => $voucherDate,
            'currency_id' => $currencyId,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'payment_method' => $paymentMethod->value(),
            'amount' => $this->decimalValue($data['amount'] ?? 0),
            'cheque_number' => $paymentMethod === AccountingPaymentMethod::CHEQUE ? ($data['cheque_number'] ?? null) : null,
            'cheque_bank_name' => $paymentMethod === AccountingPaymentMethod::CHEQUE ? ($data['cheque_bank_name'] ?? null) : null,
            'cheque_date' => $paymentMethod === AccountingPaymentMethod::CHEQUE ? ($data['cheque_date'] ?? null) : null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function createJournalEntry(PaymentVoucher $voucher): JournalEntry
    {
        return app(JournalEntryService::class)->createDraft([
            'entry_date' => $voucher->voucher_date?->toDateString(),
            'reference_no' => $voucher->reference_no,
            'source_module' => self::SOURCE_MODULE,
            'source_type' => 'PaymentVoucher',
            'sourceable_id' => $voucher->id,
            'sourceable_type' => $voucher::class,
            'description' => __('Payment voucher').' '.$voucher->reference_no,
            'created_by' => $voucher->created_by ?? auth()->id(),
        ], [
            [
                'account_id' => app(PartyAccounts::class)->supplierAccountId($voucher->client_id),
                'branch_id' => $voucher->branch_id,
                'currency_id' => $voucher->currency_id,
                'exchange_rate' => $voucher->exchange_rate,
                'debit' => $voucher->amount,
                'credit' => 0,
                'description' => __('Payment voucher payable').' '.$voucher->reference_no,
                'created_by' => $voucher->created_by ?? auth()->id(),
            ],
            [
                'account_id' => $voucher->account_id,
                'branch_id' => $voucher->branch_id,
                'currency_id' => $voucher->currency_id,
                'exchange_rate' => $voucher->exchange_rate,
                'debit' => 0,
                'credit' => $voucher->amount,
                'description' => __('Payment voucher').' '.$voucher->reference_no,
                'created_by' => $voucher->created_by ?? auth()->id(),
            ],
        ]);
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}
