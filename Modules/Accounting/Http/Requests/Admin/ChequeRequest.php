<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\Account;

/**
 * تحصيل الشيك: الحساب البنكي الذي دخل اليه (او خرج منه) المبلغ، حساب طرفي.
 */
class ChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clearing_account_id' => [
                'required',
                'integer',
                Rule::exists((new Account)->getTable(), 'id')->where('is_postable', true)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['clearing_account_id' => __('Bank Account')];
    }
}
