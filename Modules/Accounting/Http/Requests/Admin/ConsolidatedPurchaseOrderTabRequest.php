<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderBankCreditType;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderPaymentType;
use Modules\Accounting\Enums\FinancialEntryStatus;
use Modules\Accounting\Enums\ReappraisalStatus;
use Modules\Accounting\Enums\ShippingClassification;
use Modules\Accounting\Enums\ShippingTerms;
use Modules\Accounting\Enums\ShippingType;

/**
 * سجل في احد تبويبات الطلبية التجميعية (شحن، تأمين، تخليص، تسليم، مرفقات، ملاحظات، تكاليف
 * اضافية، اعادة تخمين، عروض اسعار، دفعات، اعتمادات بنكية). التبويب من مسار الطلب {tab}، وبكل
 * تبويب قواعده؛ الملفات تُرفع مع الطلب (attachment، file، copies/originals للتخليص).
 */
class ConsolidatedPurchaseOrderTabRequest extends FormRequest
{
    public const TABS = ['shipments', 'insurances', 'clearances', 'deliveries', 'attachments', 'notes', 'additional-costs', 'reappraisals', 'price-offers', 'payments', 'bank-credits'];

    private const FILE = ['nullable', 'file', 'max:20480'];

    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    public function tab(): string
    {
        return (string) $this->route('tab');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $users = 'exists:users,id';
        $currency = ['required', 'integer', 'exists:currencies,id'];
        $money = ['required', 'numeric', 'min:0'];
        $optionalMoney = ['nullable', 'numeric', 'min:0'];
        $prefix = config('accounting.table_prefix');
        $orderItems = ['nullable', 'array'];
        $pickedItems = [
            'items' => $orderItems,
            'items.*.consolidated_purchase_order_item_id' => ['required', 'integer', 'exists:'.$prefix.'consolidated_purchase_order_items,id'],
            'items.*.selected' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
        $attachment = ['attachment' => self::FILE, 'remove_attachment' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string']];

        return match ($this->tab()) {
            'shipments' => [
                'shipping_company_id' => ['required', 'integer', $users],
                'shipping_type' => ['required', Rule::enum(ShippingType::class)],
                'shipping_classification' => ['required', Rule::enum(ShippingClassification::class)],
                'container_number' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn (): bool => (int) $this->input('shipping_classification') === ShippingClassification::CONTAINER->value)],
                'invoice_number' => ['nullable', 'string', 'max:255'],
                'shipping_terms' => ['nullable', Rule::enum(ShippingTerms::class)],
                'currency_id' => $currency,
                'price' => $money,
                'net_weight' => $optionalMoney,
                'gross_weight' => $optionalMoney,
                'cbm' => $optionalMoney,
                ...$attachment,
            ],
            'insurances' => [
                'insurance_company_id' => ['required', 'integer', $users],
                ...$attachment,
            ],
            'clearances' => [
                'clearance_company_id' => ['required', 'integer', $users],
                'total_cost' => $optionalMoney,
                'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
                'notes' => ['nullable', 'string'],
                'copies' => ['nullable', 'array'],
                'copies.*' => self::FILE,
                'originals' => ['nullable', 'array'],
                'originals.*' => self::FILE,
                'remove_copies' => ['nullable', 'array'],
                'remove_originals' => ['nullable', 'array'],
            ],
            'deliveries' => [
                'delivery_company_id' => ['required', 'integer', $users],
                'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
                'notes' => ['nullable', 'string'],
                'cost_items' => ['nullable', 'array'],
                'cost_items.*.cost_element_id' => ['required', 'integer', 'exists:'.$prefix.'delivery_cost_elements,id'],
                'cost_items.*.amount' => $optionalMoney,
                'cost_items.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            'attachments' => [
                'attachment_category_id' => ['nullable', 'integer', 'exists:'.$prefix.'attachment_categories,id'],
                'file' => [$this->isMethod('post') && ! $this->route('record') ? 'required' : 'nullable', 'file', 'max:20480'],
                'notes' => ['nullable', 'string'],
            ],
            'notes' => [
                'note' => ['required', 'string'],
                'reminder_date' => ['nullable', 'date'],
            ],
            'additional-costs' => [
                'additional_cost_type_id' => ['required', 'integer', 'exists:'.$prefix.'additional_cost_types,id'],
                'currency_id' => $currency,
                'amount' => $money,
                'details' => ['nullable', 'string'],
                'notes' => ['nullable', 'string'],
                ...$pickedItems,
            ],
            'reappraisals' => [
                'reappraisal_date' => ['required', 'date'],
                'reference_no' => ['nullable', 'string', 'max:255'],
                'authority' => ['nullable', 'string', 'max:255'],
                'status' => ['required', Rule::enum(ReappraisalStatus::class)],
                'declared_value' => $money,
                'reappraised_value' => $money,
                'currency_id' => $currency,
                // البند الواحد بصف واحد، حتى يبقى مجموع كل بند واضحاً.
                'lines' => ['nullable', 'array'],
                'lines.*.reappraisal_element_id' => ['required', 'integer', 'distinct', 'exists:'.$prefix.'reappraisal_elements,id'],
                'lines.*.amount' => $money,
                'lines.*.notes' => ['nullable', 'string', 'max:255'],
                'reason' => ['nullable', 'string'],
                ...$pickedItems,
                ...$attachment,
            ],
            'price-offers' => [
                'supplier_id' => ['required', 'integer', $users],
                'currency_id' => $currency,
                'offer_date' => ['required', 'date'],
                'notes' => ['nullable', 'string'],
                'items' => ['nullable', 'array'],
                'items.*.consolidated_purchase_order_item_id' => ['nullable', 'integer'],
                'items.*.description' => ['nullable', 'string'],
                'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
                'items.*.unit_price' => $money,
                'items.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'items.*.bonus_note' => ['nullable', 'string', 'max:255'],
            ],
            'payments' => [
                'payment_type' => ['required', Rule::enum(ConsolidatedPurchaseOrderPaymentType::class)],
                'status' => ['required', Rule::enum(FinancialEntryStatus::class)],
                'amount' => $money,
                'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'due_date' => ['required', 'date'],
                'currency_id' => $currency,
                ...$attachment,
            ],
            'bank-credits' => [
                'credit_type' => ['required', Rule::enum(ConsolidatedPurchaseOrderBankCreditType::class)],
                'bank_name' => ['required', 'string', 'max:255'],
                'credit_amount' => $money,
                'duration_days' => ['nullable', 'integer', 'min:0'],
                'due_date' => ['required', 'date'],
                'status' => ['required', Rule::enum(FinancialEntryStatus::class)],
                'currency_id' => $currency,
                ...$attachment,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shipping_company_id' => __('Shipping Company'),
            'shipping_type' => __('Shipping Type'),
            'shipping_classification' => __('Shipping Classification'),
            'container_number' => __('Container Number'),
            'invoice_number' => __('Invoice Number'),
            'shipping_terms' => __('Shipping Terms'),
            'currency_id' => __('Currency'),
            'price' => __('Price'),
            'net_weight' => __('Net Weight'),
            'gross_weight' => __('Gross Weight'),
            'cbm' => __('CBM'),
            'insurance_company_id' => __('Insurance Company'),
            'clearance_company_id' => __('Clearance Company'),
            'total_cost' => __('Total Clearance Cost'),
            'delivery_company_id' => __('Delivery Company'),
            'cost_items.*.amount' => __('Amount'),
            'attachment_category_id' => __('Attachment Category'),
            'file' => __('File'),
            'attachment' => __('Attachments'),
            'note' => __('Note'),
            'reminder_date' => __('Reminder Date'),
            'additional_cost_type_id' => __('Cost Item'),
            'amount' => __('Amount'),
            'details' => __('Details'),
            'reappraisal_date' => __('Reappraisal Date'),
            'reference_no' => __('Reference No'),
            'authority' => __('Appraising Authority'),
            'status' => __('Status'),
            'declared_value' => __('Declared Value'),
            'reappraised_value' => __('Reappraised Value'),
            'lines.*.reappraisal_element_id' => __('Reappraisal Element'),
            'lines.*.amount' => __('Value'),
            'reason' => __('Reappraisal Reason'),
            'supplier_id' => __('Supplier'),
            'offer_date' => __('Offer Date'),
            'items.*.unit_price' => __('Price'),
            'items.*.discount_rate' => __('Discount'),
            'payment_type' => __('Payment Type'),
            'percentage' => __('Percentage'),
            'due_date' => __('Due Date'),
            'credit_type' => __('Credit Type'),
            'bank_name' => __('Bank'),
            'credit_amount' => __('Credit Amount'),
            'duration_days' => __('Duration (Days)'),
            'notes' => __('Notes'),
        ];
    }
}
