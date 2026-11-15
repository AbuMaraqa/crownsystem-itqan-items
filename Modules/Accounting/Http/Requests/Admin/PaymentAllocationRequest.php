<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تخصيص مبلغ من سند او مرتجع على فاتورة مفتوحة. المبلغ الفارغ = المقترح (اقل الاثنين:
 * غير المخصَّص والمتبقي)؛ السقف تفحصه PaymentAllocationService.
 */
class PaymentAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([',', ' '], '', $this->input('amount'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invoice_id' => __('Invoice'),
            'amount' => __('Amount to Allocate'),
        ];
    }
}
