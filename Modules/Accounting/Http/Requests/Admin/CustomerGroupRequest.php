<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;

class CustomerGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'profit_margin' => ['required', 'numeric', 'min:0', 'max:1000'],
            'price_list_id' => ['nullable', 'integer', Rule::exists(PriceList::class, 'id')],
            'use_last_price' => ['boolean'],
            'customer_ids' => ['array'],
            'customer_ids.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name'),
            'profit_margin' => __('Profit Margin'),
            'price_list_id' => __('Price List'),
            'use_last_price' => __('Use Last Price'),
            'customer_ids' => __('Customers'),
            'customer_ids.*' => __('Customers'),
            'notes' => __('Notes'),
            'is_active' => __('Active'),
        ];
    }
}
