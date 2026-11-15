<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_postable' => $this->boolean('is_postable'),
            'is_active' => $this->boolean('is_active'),
            'sort_order' => filled($this->input('sort_order')) ? $this->input('sort_order') : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $table = (new Account)->getTable();
        $id = (int) $this->route('account');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique($table, 'code')->ignore($id ?: null)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', Rule::exists($table, 'id')->whereNull('deleted_at')],
            'type' => ['required', 'integer', Rule::in(array_keys(AccountType::options()))],
            'normal_balance' => ['required', 'integer', Rule::in(array_keys(NormalBalance::options()))],
            'sort_order' => ['nullable', 'integer'],
            'is_postable' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('Code'),
            'name' => __('Name'),
            'description' => __('Description'),
            'parent_id' => __('Parent Account'),
            'type' => __('Type'),
            'normal_balance' => __('Normal Balance'),
            'sort_order' => __('Sort Order'),
        ];
    }
}
