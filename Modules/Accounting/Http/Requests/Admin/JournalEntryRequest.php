<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\Account;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;

/**
 * القيد اليدوي: التاريخ والمرجع والبيان، وسطران على الاقل. التوازن والحسابات الطرفية
 * والفترة المفتوحة تفحصها JournalEntryService.
 */
class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * سعر الصرف الفارغ = 1، والمدين او الدائن الفارغ = 0 (كما في نموذج Filament السابق).
     */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $this->merge([
            'lines' => array_map(fn ($line) => is_array($line) ? [
                ...$line,
                'exchange_rate' => filled($line['exchange_rate'] ?? null) ? $line['exchange_rate'] : 1,
                'debit' => filled($line['debit'] ?? null) ? $line['debit'] : 0,
                'credit' => filled($line['credit'] ?? null) ? $line['credit'] : 0,
            ] : $line, $lines),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists((new Account)->getTable(), 'id')],
            'lines.*.branch_id' => ['nullable', 'integer', Rule::exists((new Branch)->getTable(), 'id')],
            'lines.*.currency_id' => ['required', 'integer', Rule::exists((new Currency)->getTable(), 'id')],
            'lines.*.exchange_rate' => ['required', 'numeric', 'min:0.00000001'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entry_date' => __('Entry Date'),
            'reference_no' => __('Reference No'),
            'description' => __('Description'),
            'lines' => __('Journal Lines'),
            'lines.*.account_id' => __('Account'),
            'lines.*.branch_id' => __('Branch'),
            'lines.*.currency_id' => __('Currency'),
            'lines.*.exchange_rate' => __('Exchange Rate'),
            'lines.*.debit' => __('Debit'),
            'lines.*.credit' => __('Credit'),
            'lines.*.description' => __('Description'),
        ];
    }
}
