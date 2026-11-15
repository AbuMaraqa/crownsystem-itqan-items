<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * السنة المالية: الاسم والتاريخان. عدم التداخل مع سنة اخرى وتغيير تواريخ سنة عليها قيود
 * تفحصها FiscalYearService.
 */
class FiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_default' => $this->boolean('is_default')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_default' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'starts_at' => __('Start Date'),
            'ends_at' => __('End Date'),
            'is_default' => __('Default'),
        ];
    }
}
