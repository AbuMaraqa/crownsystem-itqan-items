<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * رأس الطلبية التجميعية في صفحة التعديل: التاريخان والمتابِع والملاحظات. البنود يديرها تبويب
 * الاصناف، والطرف والعملة تُحدَّد عند الانشاء.
 */
class ConsolidatedPurchaseOrderHeaderRequest extends FormRequest
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
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'followed_up_by' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_date' => __('Order Date'),
            'expected_delivery_date' => __('Expected Delivery Date'),
            'followed_up_by' => __('Followed Up By'),
            'notes' => __('Notes'),
        ];
    }
}
