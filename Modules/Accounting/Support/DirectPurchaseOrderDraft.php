<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Items\Support\PriceGroupSearch;
use Modules\Items\Support\ProductSearch;

/**
 * يبني حالة طلبية شراء جديدة «شحن مباشر للزبون» من طلبية بيع توريد مباشر:
 * المورد المقترح والزبون وطلبية البيع، وبنود البضاعة بكمياتها وقطعها بلا اسعار
 * (السعر يأتي من عرض سعر الشراء). الخدمات لا تدخل طلبية الشراء.
 */
class DirectPurchaseOrderDraft
{
    public static function find(mixed $salesOrderId): ?SalesOrder
    {
        if (blank($salesOrderId)) {
            return null;
        }

        return SalesOrder::query()
            ->with(['client', 'items.product', 'items.pieces.product'])
            ->whereKey($salesOrderId)
            ->whereNotIn('status', [SalesOrderStatus::DRAFT->value(), SalesOrderStatus::CANCELLED->value()])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function state(SalesOrder $order): array
    {
        $goods = $order->items->reject(fn ($item): bool => $item->isService())->values();
        $payloads = ProductSearch::invoiceItemPayloads($goods->pluck('product_id')->all());

        // سطر مجموعة السعر (التصنيف × اللون) ينتقل بمجموعته واسمها، وقطعه ألوان مقاطعه.
        $groupLabels = PriceGroupSearch::labels($goods->filter->isPriceGroupLine()->pluck('category_finish_id')->all());

        $items = $goods->map(function ($item) use ($payloads, $groupLabels): array {
            $payload = $payloads[(int) $item->product_id] ?? [];
            $isGroup = $item->isPriceGroupLine();
            $label = $isGroup ? ($groupLabels[(int) $item->category_finish_id] ?? $item->description) : ProductSearch::label($item->product_id);

            return [
                '_key' => 'sales-order-item-'.$item->id,
                'id' => null,
                'row_type' => 'item',
                'has_sub_products' => $isGroup,
                'product_id' => $item->product_id,
                'category_finish_id' => $item->category_finish_id,
                'product_label' => $label,
                'product_search' => $label,
                'barcode' => $item->barcode,
                'description' => $item->description,
                'quantity' => number_format((float) $item->quantity, 4, '.', ''),
                'unit_price' => '0.0000',
                'discount_mode' => 'rate',
                'discount_rate' => '0.0000',
                'discount_amount' => '0.0000',
                'line_total' => '0.0000',
                'pricing_type' => $isGroup ? 'weight' : ($item->product?->pricing_type ?? 'simple'),
                'total_weight' => number_format((float) $item->total_weight, 4, '.', ''),
                'length' => $payload['length'] ?? null,
                'actual_weight' => $item->actual_weight,
                'notes' => $item->notes,
                'pieces' => $item->pieces->map(fn ($piece) => [
                    '_key' => 'sales-order-piece-'.$piece->id,
                    'id' => null,
                    'product_id' => $piece->product_id,
                    'product_label' => ProductSearch::label($piece->product_id) ?? $piece->description,
                    'barcode' => $piece->barcode,
                    'description' => $piece->description,
                    'piece_count' => $piece->piece_count,
                    'piece_length' => $piece->piece_length,
                    'weight_per_meter' => $piece->weight_per_meter,
                    'total_weight' => $piece->total_weight,
                    'image_url' => $piece->product?->image,
                ])->values()->toArray(),
            ];
        })->toArray();

        return array_filter([
            'client_id' => $order->direct_supplier_id,
            'branch_id' => $order->branch_id,
            'delivery_type' => PurchaseOrderDeliveryType::DIRECT->value,
            'direct_customer_id' => $order->client_id,
            'direct_sales_order_id' => $order->id,
            'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
            'notes' => __('Direct shipment to customer :customer for sales order :reference.', [
                'customer' => $order->client?->name,
                'reference' => $order->reference_no,
            ]),
            'items' => $items,
        ], fn ($value) => $value !== null);
    }
}
