<?php

namespace Modules\Core\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Spatie\Permission\Models\Role;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // وسيط can على المسار يقرر
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->route('user') !== null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:255'],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['bail', 'integer', Rule::exists((new Role)->getTable(), 'id')],
            'branch_id' => ['bail', 'nullable', 'integer', Rule::exists((new Branch)->getTable(), 'id')],
            'customer_group_id' => ['bail', 'nullable', 'integer', Rule::exists((new CustomerGroup)->getTable(), 'id')],
            'profit_margin' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'use_last_price' => ['nullable', 'boolean'],
            'price_list_id' => ['bail', 'nullable', 'integer', Rule::exists((new PriceList)->getTable(), 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'email' => __('Email'),
            'phone' => __('Phone'),
            'password' => __('Password'),
            'roles' => __('Roles'),
            'branch_id' => __('Branch'),
            'customer_group_id' => __('Customer Group'),
            'profit_margin' => __('Profit Margin'),
            'use_last_price' => __('Use Last Price'),
            'price_list_id' => __('Price List'),
        ];
    }
}
