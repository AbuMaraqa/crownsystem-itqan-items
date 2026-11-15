<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Enums\ChequeDirection;
use Modules\Accounting\Enums\ChequeStatus;

class ChequeService
{
    private const SOURCE_MODULE = 'Accounting';

    public function markCleared(Cheque $cheque, int $clearingAccountId): Cheque
    {
        if ($cheque->status !== ChequeStatus::UNDER_COLLECTION) {
            throw new DomainException(__('Only cheques under collection can be marked as cleared.'));
        }

        return DB::transaction(function () use ($cheque, $clearingAccountId) {
            $cheque = Cheque::query()->lockForUpdate()->findOrFail($cheque->id);

            // يُعاد الفحص بعد القفل حتى لا يُحصَّل الشيك مرتين في طلبين متزامنين.
            if ($cheque->status !== ChequeStatus::UNDER_COLLECTION) {
                throw new DomainException(__('Only cheques under collection can be marked as cleared.'));
            }

            $isIncoming = $cheque->direction === ChequeDirection::INCOMING;

            $entry = app(JournalEntryService::class)->createDraft([
                'entry_date' => now()->toDateString(),
                'reference_no' => $cheque->cheque_number,
                'source_module' => self::SOURCE_MODULE,
                'source_type' => 'Cheque',
                'sourceable_id' => $cheque->id,
                'sourceable_type' => $cheque::class,
                'description' => __('Cheque cleared').' '.$cheque->cheque_number,
                'created_by' => auth()->id(),
            ], [
                [
                    'account_id' => $isIncoming ? $clearingAccountId : $cheque->account_id,
                    'branch_id' => $cheque->branch_id,
                    'currency_id' => $cheque->currency_id,
                    'exchange_rate' => $cheque->exchange_rate,
                    'debit' => $cheque->amount,
                    'credit' => 0,
                    'description' => __('Cheque cleared').' '.$cheque->cheque_number,
                    'created_by' => auth()->id(),
                ],
                [
                    'account_id' => $isIncoming ? $cheque->account_id : $clearingAccountId,
                    'branch_id' => $cheque->branch_id,
                    'currency_id' => $cheque->currency_id,
                    'exchange_rate' => $cheque->exchange_rate,
                    'debit' => 0,
                    'credit' => $cheque->amount,
                    'description' => __('Cheque cleared').' '.$cheque->cheque_number,
                    'created_by' => auth()->id(),
                ],
            ]);

            app(JournalEntryService::class)->post($entry);

            $cheque->update([
                'status' => ChequeStatus::CLEARED->value(),
                'clearing_account_id' => $clearingAccountId,
                'journal_entry_id' => $entry->id,
                'cleared_at' => now(),
            ]);

            return $cheque->refresh()->load(['client', 'account', 'clearingAccount', 'currency', 'journalEntry']);
        });
    }

    public function markBounced(Cheque $cheque): Cheque
    {
        return $this->reverseOriginalEntry($cheque, ChequeStatus::BOUNCED);
    }

    public function cancel(Cheque $cheque): Cheque
    {
        return $this->reverseOriginalEntry($cheque, ChequeStatus::CANCELLED);
    }

    private function reverseOriginalEntry(Cheque $cheque, ChequeStatus $targetStatus): Cheque
    {
        if ($cheque->status !== ChequeStatus::UNDER_COLLECTION) {
            throw new DomainException(__('Only cheques under collection can have their status changed.'));
        }

        return DB::transaction(function () use ($cheque, $targetStatus) {
            $cheque = Cheque::query()->with('chequeable.journalEntry')->lockForUpdate()->findOrFail($cheque->id);

            $originalEntry = $cheque->chequeable?->journalEntry;

            if (! $originalEntry) {
                throw new DomainException(__('The original voucher journal entry could not be found.'));
            }

            $description = $targetStatus === ChequeStatus::BOUNCED
                ? __('Cheque bounced').' '.$cheque->cheque_number
                : __('Cheque cancelled').' '.$cheque->cheque_number;

            app(JournalEntryService::class)->void($originalEntry, $description);

            $reversal = $originalEntry->reversals()->latest('id')->first();

            $cheque->update([
                'status' => $targetStatus->value(),
                'journal_entry_id' => $reversal?->id,
            ]);

            // السند الذي سقط شيكه يُلغى وتُفك تخصيصاته، والا بقيت فواتيره «مسددة» بينما عاد
            // الدين في حساب الطرف. قيده عُكس اعلاه فلا يُعكس مرة ثانية.
            match (true) {
                $cheque->chequeable instanceof ReceiptVoucher => app(ReceiptVoucherService::class)->cancel($cheque->chequeable, $description, true),
                $cheque->chequeable instanceof PaymentVoucher => app(PaymentVoucherService::class)->cancel($cheque->chequeable, $description, true),
                default => null,
            };

            return $cheque->refresh()->load(['client', 'account', 'currency', 'journalEntry']);
        });
    }
}
