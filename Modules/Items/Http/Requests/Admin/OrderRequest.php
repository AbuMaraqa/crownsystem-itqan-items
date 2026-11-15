<?php

namespace Modules\Items\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Items\Enums\OrderStatus;
use Modules\Items\Enums\PaymentMethod;
use Modules\Items\Enums\PaymentStatus;

/**
 * نموذج الطلب في لوحة التحكم (اضافة وتعديل). سعر السطر والمجاميع تُحسب في OrderService.
 */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prefix = config('items.table_prefix');

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer'],
            'city_id' => ['required', 'integer'],
            'district_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer', 'exists:'.$prefix.'products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', new Enum(OrderStatus::class)],
            'payment_method' => ['required', new Enum(PaymentMethod::class)],
            'payment_status' => ['required', new Enum(PaymentStatus::class)],
            'delivery_address' => ['required', 'string'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => __('Customer'),
            'branch_id' => __('Branch'),
            'city_id' => __('City'),
            'district_id' => __('District'),
            'items' => __('Products'),
            'items.*.product_id' => __('Product'),
            'items.*.quantity' => __('Quantity'),
            'delivery_fee' => __('Delivery Fee'),
            'status' => __('Order Status'),
            'payment_method' => __('Payment Method'),
            'payment_status' => __('Payment Status'),
            'delivery_address' => __('Delivery Address'),
            'contact_phone' => __('Contact Phone'),
        ];
    }
}
