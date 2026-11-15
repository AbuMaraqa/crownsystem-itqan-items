<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;

/**
 * حقول سند القبض وسند الصرف (متطابقة). سعر الصرف للعرض فقط: الخدمة تحسبه من العملة والتاريخ.
 */
abstract class VoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // وسيط can على المسار يقرر
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cheque = 'required_if:payment_method,'.AccountingPaymentMethod::CHEQUE->value();

        return [
            'client_id' => ['bail', 'required', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'branch_id' => ['bail', 'nullable', 'integer', Rule::exists((new Branch)->getTable(), 'id')],
            'account_id' => ['bail', 'required', 'integer', Rule::exists((new Account)->getTable(), 'id')],
            'voucher_date' => ['required', 'date'],
            'currency_id' => ['bail', 'required', 'integer', Rule::exists((new Currency)->getTable(), 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.00000001'],
            'payment_method' => ['required', Rule::enum(AccountingPaymentMethod::class)],
            'amount' => ['required', 'numeric', 'min:0.0001'],
            'cheque_number' => [$cheque, 'nullable', 'string', 'max:255'],
            'cheque_bank_name' => ['nullable', 'string', 'max:255'],
            'cheque_date' => [$cheque, 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Customer / Supplier'),
            'branch_id' => __('Branch'),
            'account_id' => $this->accountLabel(),
            'voucher_date' => __('Voucher Date'),
            'currency_id' => __('Currency'),
            'exchange_rate' => __('Exchange Rate'),
            'payment_method' => __('Payment Method'),
            'amount' => __('Amount'),
            'cheque_number' => __('Cheque Number'),
            'cheque_bank_name' => __('Cheque Bank'),
            'cheque_date' => __('Cheque Due Date'),
            'notes' => __('Notes'),
        ];
    }

    /**
     * Paid From في سند الصرف، و Received Into في سند القبض.
     */
    abstract protected function accountLabel(): string;
}
