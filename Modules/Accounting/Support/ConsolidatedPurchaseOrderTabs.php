<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderAdditionalCost;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderAttachment;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderBankCredit;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderClearance;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderDelivery;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderInsurance;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderNote;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderPayment;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderPriceOffer;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderReappraisal;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderShipment;
use Modules\Accounting\Entities\DeliveryCostElement;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderBankCreditType;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderPaymentType;
use Modules\Accounting\Enums\FinancialEntryStatus;
use Modules\Accounting\Enums\ReappraisalStatus;
use Modules\Accounting\Enums\ShippingClassification;
use Modules\Accounting\Enums\ShippingTerms;
use Modules\Accounting\Enums\ShippingType;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderPickerService;
use Modules\Core\Entities\User;
use Modules\Core\Support\UserCellData;

/**
 * ما يعرضه كل تبويب في صفحة الطلبية التجميعية (Vue): صف العرض، وقيم نموذج التعديل لكل سجل.
 * القراءة فقط؛ الكتابة في خدمات الطلبية (Logistics, FinancialFile, PriceOffer, Picker).
 */
final class ConsolidatedPurchaseOrderTabs
{
    public const DISK = 'accounting';

    /**
     * @return array<string, mixed>
     */
    public static function all(ConsolidatedPurchaseOrder $order): array
    {
        return [
            'items' => self::items($order),
            'shipments' => self::shipments($order),
            'insurances' => self::insurances($order),
            'clearances' => self::clearances($order),
            'additionalCosts' => self::additionalCosts($order),
            'reappraisals' => self::reappraisals($order),
            'deliveries' => self::deliveries($order),
            'attachments' => self::attachments($order),
            'notes' => self::notes($order),
            'priceOffers' => self::priceOffers($order),
            'payments' => self::payments($order),
            'bankCredits' => self::bankCredits($order),
            'orderItems' => self::orderItemChoices($order),
        ];
    }

    /**
     * تبويب الاصناف: كل بند صنف رئيسي، وتحته جدول اصنافه الفرعية، ومصدره في طلبية الشراء
     * بكميته الاصلية والمتبقي منها.
     *
     * @return array{rows: list<array<string, mixed>>, has_weight_items: bool}
     */
    public static function items(ConsolidatedPurchaseOrder $order): array
    {
        $items = $order->items()
            ->with(['product.media', 'sourcePurchaseOrderItem.purchaseOrder', 'sourcePurchaseOrderItem.product:id,pricing_type', 'pieces'])
            ->orderBy('id')
            ->get();

        $mainItems = PurchaseOrderMainItems::for($items);
        $consumption = PurchaseOrderConsumption::for($order->id);
        $sources = $items->pluck('sourcePurchaseOrderItem')->filter();
        $consumed = $consumption->consumed($sources->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $picker = app(ConsolidatedPurchaseOrderPickerService::class);

        $rows = $items->map(function (ConsolidatedPurchaseOrderItem $item) use ($mainItems, $consumed, $picker): array {
            $isWeight = $item->product?->pricing_type === 'weight';
            $weights = PurchaseOrderMainItems::weights($item);
            $source = $item->sourcePurchaseOrderItem;
            $sourceIsWeight = $source && PurchaseOrderConsumption::isWeightPriced($source);

            return [
                'id' => (int) $item->id,
                'image' => $item->product?->image ?: null,
                'description' => (string) $item->description,
                'main' => $mainItems[$item->id],
                'source' => $source?->purchase_order_id ? ['id' => (int) $source->purchase_order_id, 'reference_no' => $source->purchaseOrder?->reference_no] : null,
                'is_weight' => $isWeight,
                'quantity' => Number::quantity($isWeight ? $item->total_weight : $item->quantity),
                'quantity_value' => Number::clean($item->quantity),
                'original' => $source ? ($sourceIsWeight ? __('Original Weight') : __('Original Quantity')).': '.Number::quantity(PurchaseOrderConsumption::ordered($source)) : null,
                'remaining_text' => $source ? ($sourceIsWeight ? __('Remaining Weight') : __('Remaining Quantity')).': '.Number::quantity(PurchaseOrderConsumption::remaining($source, $consumed)) : null,
                'remaining' => $isWeight ? null : $picker->remainingFor($item),
                'total_weight' => $weights['total_weight'] !== null ? Number::quantity($weights['total_weight']) : null,
                'actual_weight' => $weights['actual_weight'] !== null ? Number::quantity($weights['actual_weight']) : null,
                'unit_price' => Number::money($item->unit_price),
                'line_total' => Number::money($item->line_total),
            ];
        })->values()->all();

        return ['rows' => $rows, 'has_weight_items' => $items->contains(fn (ConsolidatedPurchaseOrderItem $item): bool => $item->product?->pricing_type === 'weight')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function shipments(ConsolidatedPurchaseOrder $order): array
    {
        return $order->shipments()->with(['shippingCompany.media', 'currency', 'createdBy.media'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderShipment $shipment): array => [
                'id' => $shipment->id,
                'company' => UserCellData::of($shipment->shippingCompany),
                'shipping_type' => $shipment->shipping_type?->getLabel(),
                'container_number' => $shipment->container_number,
                'invoice_number' => $shipment->invoice_number,
                'created_by' => UserCellData::of($shipment->createdBy),
                'currency' => $shipment->currency?->code,
                'price' => number_format((float) $shipment->price, 2),
                'notes' => $shipment->notes,
                'attachment' => self::file($shipment->attachment_path),
                'form' => [
                    'shipping_company_id' => $shipment->shipping_company_id,
                    'shipping_type' => $shipment->shipping_type?->value,
                    'shipping_classification' => $shipment->shipping_classification?->value,
                    'container_number' => $shipment->container_number,
                    'invoice_number' => $shipment->invoice_number,
                    'shipping_terms' => $shipment->shipping_terms?->value,
                    'currency_id' => $shipment->currency_id,
                    'price' => Number::clean($shipment->price),
                    'net_weight' => self::clean($shipment->net_weight),
                    'gross_weight' => self::clean($shipment->gross_weight),
                    'cbm' => self::clean($shipment->cbm),
                    'notes' => $shipment->notes,
                ],
                'party' => self::option($shipment->shippingCompany),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function insurances(ConsolidatedPurchaseOrder $order): array
    {
        return $order->insurances()->with(['insuranceCompany.media', 'createdBy.media'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderInsurance $insurance): array => [
                'id' => $insurance->id,
                'company' => UserCellData::of($insurance->insuranceCompany),
                'notes' => $insurance->notes,
                'created_by' => UserCellData::of($insurance->createdBy),
                'created_at' => $insurance->created_at?->format('d/m/Y'),
                'attachment' => self::file($insurance->attachment_path),
                'form' => ['insurance_company_id' => $insurance->insurance_company_id, 'notes' => $insurance->notes],
                'party' => self::option($insurance->insuranceCompany),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function clearances(ConsolidatedPurchaseOrder $order): array
    {
        return $order->clearances()->with(['clearanceCompany.media', 'currency', 'createdBy.media', 'attachments'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderClearance $clearance): array => [
                'id' => $clearance->id,
                'company' => UserCellData::of($clearance->clearanceCompany),
                'total_cost' => $clearance->total_cost !== null ? number_format((float) $clearance->total_cost, 2) : null,
                'currency' => $clearance->currency?->code,
                'created_by' => UserCellData::of($clearance->createdBy),
                'created_at' => $clearance->created_at?->format('d/m/Y'),
                'notes' => $clearance->notes,
                'form' => [
                    'clearance_company_id' => $clearance->clearance_company_id,
                    'total_cost' => self::clean($clearance->total_cost),
                    'currency_id' => $clearance->currency_id,
                    'notes' => $clearance->notes,
                ],
                'files' => $clearance->attachments->mapWithKeys(fn ($attachment): array => [
                    (int) $attachment->attachment_type_id => ['copy' => self::file($attachment->copy_path), 'original' => self::file($attachment->original_path)],
                ])->all(),
                'party' => self::option($clearance->clearanceCompany),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function additionalCosts(ConsolidatedPurchaseOrder $order): array
    {
        return $order->additionalCosts()->with(['additionalCostType', 'currency', 'createdBy.media', 'items.orderItem'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderAdditionalCost $cost): array => [
                'id' => $cost->id,
                'type' => $cost->additionalCostType?->name,
                'details' => $cost->details,
                'notes' => $cost->notes,
                'items' => $cost->items->map(fn ($item): string => ($item->orderItem?->description ?: '—').(filled($item->notes) ? ' — '.$item->notes : ''))->all(),
                'amount' => number_format((float) $cost->amount, 2),
                'amount_value' => (float) $cost->amount,
                'currency' => $cost->currency?->code,
                'created_by' => UserCellData::of($cost->createdBy),
                'form' => [
                    'additional_cost_type_id' => $cost->additional_cost_type_id,
                    'details' => $cost->details,
                    'notes' => $cost->notes,
                    'amount' => Number::clean($cost->amount),
                    'currency_id' => $cost->currency_id,
                    'items' => self::chosenItems($cost->items),
                ],
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function reappraisals(ConsolidatedPurchaseOrder $order): array
    {
        return $order->reappraisals()->with(['currency', 'createdBy.media', 'lines.element', 'items.orderItem'])->orderByDesc('reappraisal_date')->get()
            ->map(fn (ConsolidatedPurchaseOrderReappraisal $reappraisal): array => [
                'id' => $reappraisal->id,
                'reappraisal_date' => $reappraisal->reappraisal_date?->format('d/m/Y'),
                'reference_no' => $reappraisal->reference_no,
                'authority' => $reappraisal->authority,
                'declared_value' => number_format((float) $reappraisal->declared_value, 2),
                'reappraised_value' => number_format((float) $reappraisal->reappraised_value, 2),
                'difference' => number_format((float) $reappraisal->difference, 2),
                // الزيادة تُلوَّن خطراً والنقصان نجاحاً.
                'difference_tone' => (float) $reappraisal->difference > 0 ? 'danger' : ((float) $reappraisal->difference < 0 ? 'success' : 'neutral'),
                'currency' => $reappraisal->currency?->code,
                'lines' => $reappraisal->lines->map(fn ($line): string => ($line->element?->name ?: '—').': '.number_format((float) $line->amount, 2))->all(),
                'lines_total' => $reappraisal->lines->isNotEmpty() ? number_format((float) $reappraisal->lines->sum('amount'), 2) : null,
                'items' => $reappraisal->items->map(fn ($item): string => ($item->orderItem?->description ?: '—').(filled($item->notes) ? ' — '.$item->notes : ''))->all(),
                'status' => $reappraisal->status ? ['label' => $reappraisal->status->getLabel(), 'tone' => StatusBadge::tone($reappraisal->status->getColor())] : null,
                'created_by' => UserCellData::of($reappraisal->createdBy),
                'attachment' => self::file($reappraisal->attachment_path),
                'form' => [
                    'reappraisal_date' => $reappraisal->reappraisal_date?->toDateString(),
                    'authority' => $reappraisal->authority,
                    'reference_no' => $reappraisal->reference_no,
                    'declared_value' => Number::clean($reappraisal->declared_value),
                    'reappraised_value' => Number::clean($reappraisal->reappraised_value),
                    'currency_id' => $reappraisal->currency_id,
                    'status' => $reappraisal->status?->value,
                    'lines' => $reappraisal->lines->map(fn ($line): array => [
                        'reappraisal_element_id' => $line->reappraisal_element_id,
                        'amount' => Number::clean($line->amount),
                        'notes' => $line->notes,
                    ])->values()->all(),
                    'items' => self::chosenItems($reappraisal->items),
                    'reason' => $reappraisal->reason,
                    'notes' => $reappraisal->notes,
                ],
                // البند المحفوظ يبقى خياراً ولو أُوقف او حُذف، حتى لا يظهر الصف فارغاً.
                'element_options' => $reappraisal->lines->filter(fn ($line) => $line->element)->map(fn ($line): array => ['value' => (int) $line->element->id, 'label' => (string) $line->element->name])->values()->all(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function deliveries(ConsolidatedPurchaseOrder $order): array
    {
        return $order->deliveries()->with(['deliveryCompany.media', 'currency', 'costItems', 'createdBy.media'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderDelivery $delivery): array => [
                'id' => $delivery->id,
                'company' => UserCellData::of($delivery->deliveryCompany),
                'total' => number_format((float) $delivery->costItems->sum('amount'), 2),
                'currency' => $delivery->currency?->code,
                'created_by' => UserCellData::of($delivery->createdBy),
                'created_at' => $delivery->created_at?->format('d/m/Y'),
                'notes' => $delivery->notes,
                'form' => [
                    'delivery_company_id' => $delivery->delivery_company_id,
                    'currency_id' => $delivery->currency_id,
                    'notes' => $delivery->notes,
                    'cost_items' => $delivery->costItems->mapWithKeys(fn ($item): array => [
                        (int) $item->cost_element_id => ['amount' => self::clean($item->amount), 'notes' => $item->notes],
                    ])->all(),
                ],
                'party' => self::option($delivery->deliveryCompany),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function attachments(ConsolidatedPurchaseOrder $order): array
    {
        return $order->attachments()->with(['createdBy.media', 'attachmentCategory'])->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderAttachment $attachment): array => [
                'id' => $attachment->id,
                'category' => $attachment->attachmentCategory?->name,
                'file' => self::file($attachment->file_path),
                'notes' => $attachment->notes,
                'created_by' => UserCellData::of($attachment->createdBy),
                'created_at' => $attachment->created_at?->format('d/m/Y'),
                'form' => ['attachment_category_id' => $attachment->attachment_category_id, 'notes' => $attachment->notes],
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function notes(ConsolidatedPurchaseOrder $order): array
    {
        return $order->orderNotes()->with('createdBy.media')->latest()->get()
            ->map(fn (ConsolidatedPurchaseOrderNote $note): array => [
                'id' => $note->id,
                'note' => $note->note,
                'reminder_date' => $note->reminder_date?->format('d/m/Y'),
                'created_by' => UserCellData::of($note->createdBy),
                'created_at' => $note->created_at?->format('d/m/Y'),
                'form' => ['note' => $note->note, 'reminder_date' => $note->reminder_date?->toDateString()],
            ])->all();
    }

    /**
     * عروض الاسعار والترسية (التبويبان مخفيان مؤقتاً كما في الصفحة القديمة).
     *
     * @return list<array<string, mixed>>
     */
    public static function priceOffers(ConsolidatedPurchaseOrder $order): array
    {
        $awardedId = $order->award()->value('price_offer_id');

        return $order->priceOffers()->with(['supplier.media', 'currency', 'items'])->orderByDesc('offer_date')->get()
            ->map(fn (ConsolidatedPurchaseOrderPriceOffer $offer): array => [
                'id' => $offer->id,
                'supplier' => UserCellData::of($offer->supplier),
                'offer_date' => $offer->offer_date?->format('d/m/Y'),
                'currency' => $offer->currency?->code,
                'total_amount' => number_format((float) $offer->total_amount, 2),
                'total_value' => (float) $offer->total_amount,
                'awarded' => (int) $awardedId === (int) $offer->id,
                'notes' => $offer->notes,
                'form' => [
                    'supplier_id' => $offer->supplier_id,
                    'currency_id' => $offer->currency_id,
                    'offer_date' => $offer->offer_date?->toDateString(),
                    'notes' => $offer->notes,
                    'items' => $offer->items->map(fn ($item): array => [
                        'consolidated_purchase_order_item_id' => $item->consolidated_purchase_order_item_id,
                        'description' => $item->description,
                        'quantity' => Number::clean($item->quantity),
                        'unit_price' => Number::clean($item->unit_price),
                        'discount_rate' => Number::clean($item->discount_rate),
                        'bonus_note' => $item->bonus_note,
                        'line_total' => Number::clean($item->line_total),
                    ])->values()->all(),
                ],
                'party' => self::option($offer->supplier),
            ])->all();
    }

    /**
     * بنود عرض سعر جديد: كل اصناف الطلبية بكمياتها (الموزون بوزنه الكلي) وسعر صفر.
     *
     * @return list<array<string, mixed>>
     */
    public static function blankOfferItems(ConsolidatedPurchaseOrder $order): array
    {
        return $order->items()->with('product:id,pricing_type')->orderBy('id')->get()
            ->map(fn (ConsolidatedPurchaseOrderItem $item): array => [
                'consolidated_purchase_order_item_id' => $item->id,
                'description' => $item->description,
                'quantity' => Number::clean($item->product?->pricing_type === 'weight' ? $item->total_weight : $item->quantity),
                'unit_price' => '0',
                'discount_rate' => '0',
                'bonus_note' => null,
                'line_total' => '0',
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function payments(ConsolidatedPurchaseOrder $order): array
    {
        return $order->payments()->with('currency')->orderByDesc('due_date')->get()
            ->map(fn (ConsolidatedPurchaseOrderPayment $payment): array => [
                'id' => $payment->id,
                'amount' => number_format((float) $payment->amount, 2),
                'percentage' => $payment->percentage !== null ? number_format((float) $payment->percentage, 2).'%' : null,
                'due_date' => $payment->due_date?->format('d/m/Y'),
                'payment_type' => $payment->payment_type?->getLabel(),
                'status' => StatusBadge::of($payment->status),
                'currency' => $payment->currency?->code,
                'notes' => $payment->notes,
                'attachment' => self::file($payment->attachment_path),
                'form' => [
                    'payment_type' => $payment->payment_type?->value,
                    'amount' => Number::clean($payment->amount),
                    'percentage' => self::clean($payment->percentage),
                    'due_date' => $payment->due_date?->toDateString(),
                    'status' => $payment->status?->value,
                    'currency_id' => $payment->currency_id,
                    'notes' => $payment->notes,
                ],
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function bankCredits(ConsolidatedPurchaseOrder $order): array
    {
        return $order->bankCredits()->with('currency')->orderByDesc('due_date')->get()
            ->map(fn (ConsolidatedPurchaseOrderBankCredit $credit): array => [
                'id' => $credit->id,
                'credit_type' => $credit->credit_type?->getLabel(),
                'credit_amount' => number_format((float) $credit->credit_amount, 2),
                'bank_name' => $credit->bank_name,
                'due_date' => $credit->due_date?->format('d/m/Y'),
                'status' => StatusBadge::of($credit->status),
                'currency' => $credit->currency?->code,
                'notes' => $credit->notes,
                'attachment' => self::file($credit->attachment_path),
                'form' => [
                    'credit_type' => $credit->credit_type?->value,
                    'bank_name' => $credit->bank_name,
                    'credit_amount' => Number::clean($credit->credit_amount),
                    'duration_days' => $credit->duration_days,
                    'due_date' => $credit->due_date?->toDateString(),
                    'status' => $credit->status?->value,
                    'currency_id' => $credit->currency_id,
                    'notes' => $credit->notes,
                ],
            ])->all();
    }

    /**
     * اصناف الطلبية للتأشير في تكلفة اضافية او اعادة تخمين: كلها معروضة دائماً.
     *
     * @return list<array{id: int, label: string}>
     */
    public static function orderItemChoices(ConsolidatedPurchaseOrder $order): array
    {
        return $order->items()->orderBy('id')->get()
            ->map(fn (ConsolidatedPurchaseOrderItem $item): array => ['id' => (int) $item->id, 'label' => $item->description.' — '.Number::quantity($item->quantity)])
            ->all();
    }

    /**
     * قوائم الاختيار في نماذج التبويبات.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'currencyOptions' => SelectOptions::currencies(),
            'shippingTypeOptions' => SelectOptions::from(ShippingType::options()),
            'shippingClassificationOptions' => SelectOptions::from(ShippingClassification::options()),
            'shippingTermsOptions' => SelectOptions::from(ShippingTerms::options()),
            'containerClassification' => ShippingClassification::CONTAINER->value,
            'additionalCostTypeOptions' => SelectOptions::from(AdditionalCostType::options()),
            'attachmentCategoryOptions' => SelectOptions::from(AttachmentCategory::options()),
            'reappraisalElementOptions' => SelectOptions::from(ReappraisalElement::options()),
            'reappraisalStatusOptions' => SelectOptions::from(ReappraisalStatus::options()),
            'paymentTypeOptions' => SelectOptions::from(ConsolidatedPurchaseOrderPaymentType::options()),
            'bankCreditTypeOptions' => SelectOptions::from(ConsolidatedPurchaseOrderBankCreditType::options()),
            'financialStatusOptions' => SelectOptions::from(FinancialEntryStatus::options()),
            'deliveryCostElements' => DeliveryCostElement::query()->orderBy('name')->get()->map(fn (DeliveryCostElement $element): array => ['id' => (int) $element->id, 'name' => (string) $element->name])->all(),
            'clearanceAttachmentTypes' => ClearanceAttachmentType::query()->orderBy('name')->get()->map(fn (ClearanceAttachmentType $type): array => ['id' => (int) $type->id, 'name' => (string) $type->name])->all(),
        ];
    }

    /**
     * @return array{path: string, name: string, url: string}|null
     */
    public static function file(?string $path): ?array
    {
        return filled($path) ? ['path' => $path, 'name' => basename($path), 'url' => Storage::disk(self::DISK)->url($path)] : null;
    }

    /**
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $items
     * @return list<array{id: int, notes: string|null}>
     */
    private static function chosenItems(iterable $items): array
    {
        return collect($items)->map(fn ($item): array => ['id' => (int) $item->consolidated_purchase_order_item_id, 'notes' => $item->notes])->values()->all();
    }

    /**
     * @return array{value: int, label: string}|null
     */
    private static function option(?User $user): ?array
    {
        return $user ? ['value' => (int) $user->id, 'label' => (string) $user->name] : null;
    }

    private static function clean(mixed $value): ?string
    {
        return $value !== null ? Number::clean($value) : null;
    }
}
