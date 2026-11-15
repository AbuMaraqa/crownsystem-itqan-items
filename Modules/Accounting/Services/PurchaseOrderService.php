<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;
use Modules\Items\Support\CategorySearch;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * طلبية الشراء: ما يُطلب من المورد وكمياته واوزانه وموعد ومكان استلامه، وسعر اختياري.
 * السعر صفر او فارغ جائز، وسعر عرض الشراء المعتمد يتقدّم عليه. مبلغ سطر الوزن = الوزن
 * الفعلي × السعر، وصفر بدون وزن فعلي. لا عملة ولا ضريبة على الطلبية: المبالغ بالعملة الاساسية.
 */
class PurchaseOrderService
{
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data, $items);
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'purchase_order',
                $data['order_date'],
                ['prefix' => 'PORD']
            );
            $data['status'] = PurchaseOrderStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $order = PurchaseOrder::create($data);
            $this->syncItems($order, $items);

            return $order->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product']);
        });
    }

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if (! $order->canBeEdited()) {
            throw new DomainException(__('Only editable purchase orders can be updated.'));
        }

        return DB::transaction(function () use ($order, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data, $items);
            unset($data['reference_no'], $data['created_by'], $data['confirmed_at'], $data['received_at'], $data['invoiced_at']);

            $order->update($data);
            $this->syncItems($order, $items);

            return $order->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product']);
        });
    }

    /**
     * حفظ شاشة الادخال (لوحة Vue): صنف الوزن الجديد لا يُحفظ بلا اصناف فرعية لها وزن،
     * ونوع التوريد يثبت بعد اول سند استلام، ثم المرفقات (صور جديدة، محذوفة، وترتيب).
     *
     * @param  array<int, \Illuminate\Http\UploadedFile|mixed>  $attachments
     * @param  array<int, int|string>  $removedAttachments  معرفات وسائط المرفقات المحذوفة
     * @param  array<int, int|string>  $attachmentOrder  ترتيب المرفقات المحفوظة
     */
    public function save(?PurchaseOrder $order, array $data, array $attachments = [], array $removedAttachments = [], array $attachmentOrder = []): PurchaseOrder
    {
        ItemsGrid::ensureMainItemsHaveSubItems($data['items'] ?? []);

        if ($order && $order->goodsReceipts()->exists()) {
            $data = array_replace($data, [
                'delivery_type' => $order->delivery_type,
                'direct_customer_id' => $order->direct_customer_id,
                'direct_sales_order_id' => $order->direct_sales_order_id,
            ]);
        }

        $order = $order ? $this->update($order, $data) : $this->create($data);

        $this->syncAttachments($order, $attachments, $removedAttachments, $attachmentOrder);

        return $order;
    }

    /**
     * @param  array<int, mixed>  $files
     * @param  array<int, int|string>  $removed
     * @param  array<int, int|string>  $sortOrder
     */
    public function syncAttachments(PurchaseOrder $order, array $files = [], array $removed = [], array $sortOrder = []): void
    {
        if ($removed !== []) {
            $order->getMedia('attachments')
                ->filter(fn ($media): bool => in_array((string) $media->id, array_map('strval', $removed), true))
                ->each->delete();
        }

        if ($sortOrder !== []) {
            $ids = $order->getMedia('attachments')->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $sorted = array_values(array_filter(array_map('strval', $sortOrder), fn (string $id): bool => in_array($id, $ids, true)));

            if ($sorted !== []) {
                Media::setNewOrder(array_map('intval', $sorted));
            }
        }

        $files = array_values(array_filter($files));

        if ($files !== []) {
            $order->addAttachments($files);
        }

        $order->unsetRelation('media');
    }

    /**
     * عرض سعر الشراء لهذه الطلبية: يُفتح الفعّال ان وُجد، والا يُنشأ لادخال اسعار المورد.
     */
    public function openQuotation(PurchaseOrder $order): PurchaseQuotation
    {
        return $order->activePurchaseQuotation() ?? app(PurchaseQuotationService::class)->createFromOrder($order);
    }

    /**
     * عرض السعر يُنشأ لطلبية مفتوحة لم يُعرض لها سعر بعد.
     */
    public function canCreateQuotation(PurchaseOrder $order): bool
    {
        return ! in_array($order->status, [PurchaseOrderStatus::INVOICED, PurchaseOrderStatus::CANCELLED], true)
            && $order->activePurchaseQuotation() === null;
    }

    public function canConfirm(PurchaseOrder $order): bool
    {
        return $order->status === PurchaseOrderStatus::DRAFT;
    }

    /**
     * «تم التسليم للزبون» يدوياً للشحن المباشر فقط؛ طلبية المخزن تُستلم بسند استلام حتى تدخل
     * كمياتها المخزون.
     */
    public function canMarkReceived(PurchaseOrder $order): bool
    {
        return $order->isDirectShipment()
            && in_array($order->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::CONFIRMED], true);
    }

    public function canCreateGoodsReceipt(PurchaseOrder $order): bool
    {
        if ($order->isDirectShipment() || ! in_array($order->status, [PurchaseOrderStatus::CONFIRMED, PurchaseOrderStatus::INVOICED], true)) {
            return false;
        }

        return app(GoodsReceiptService::class)->outstandingLines($order)->sum('outstanding') > 0;
    }

    /**
     * سندات استلام الطلبية ونسبة ما استُلم منها (للعرض في ترويسة الطلبية). فارغ للشحن المباشر.
     *
     * @return array{receipts: \Illuminate\Support\Collection<int, GoodsReceipt>, percent: int}|array{}
     */
    public function receiving(PurchaseOrder $order): array
    {
        if ($order->isDirectShipment()) {
            return [];
        }

        $lines = app(GoodsReceiptService::class)->outstandingLines($order, null, true);
        $ordered = (float) $lines->sum('ordered');

        return [
            'receipts' => $order->goodsReceipts()->latest('id')->get(['id', 'reference_no', 'status', 'receipt_date']),
            'percent' => $ordered > 0 ? (int) round(100 * (float) $lines->sum('received') / $ordered) : 0,
        ];
    }

    /**
     * سطور الطلبية في جدول الاصناف، ورؤوس المجموعات قبل اصنافها: الرأس ليس بنداً في قاعدة
     * البيانات، فيُعاد بناؤه كلما تغيّر تصنيف البند عن سابقه، فتظهر الاصناف مجمّعة كما أُدخلت.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridRows(PurchaseOrder $order): array
    {
        $order->loadMissing(['items.product.translations', 'items.pieces.product']);

        $items = ItemsGridState::rows(
            $order->items,
            ['category_id', 'source_purchase_order_item_id', 'length'],
            extraPiece: fn ($piece): array => ['image_url' => $piece->product?->image],
        );

        $rows = [];
        $currentCategoryId = null;

        foreach ($items as $item) {
            $categoryId = $item['category_id'] ?? null;

            if (filled($categoryId) && $categoryId !== $currentCategoryId) {
                $label = CategorySearch::label($categoryId);

                $rows[] = [
                    '_key' => 'group-'.$categoryId.'-'.count($rows),
                    'id' => null,
                    'row_type' => 'category',
                    'category_id' => (int) $categoryId,
                    'category_label' => $label,
                    'product_id' => null,
                    'product_label' => $label,
                    'product_search' => $label,
                    'pricing_type' => 'simple',
                    'quantity' => null,
                    'unit_price' => '0.0000',
                    'line_total' => '0.0000',
                    'pieces' => [],
                ];
            }

            $currentCategoryId = $categoryId;
            $rows[] = $item;
        }

        return $rows;
    }

    public function delete(PurchaseOrder $order): void
    {
        if (! $order->canBeDeleted()) {
            throw new DomainException(__('Only draft purchase orders can be deleted.'));
        }

        DB::transaction(function () use ($order) {
            $order->items()->delete();
            $order->delete();
        });
    }

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::CONFIRMED, ['confirmed_at' => now()]);
    }

    public function markReceived(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::RECEIVED, ['received_at' => now()]);
    }

    public function markInvoiced(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::INVOICED, ['invoiced_at' => now()]);
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::CANCELLED);
    }

    private function transition(PurchaseOrder $order, PurchaseOrderStatus $status, array $extra = []): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $status, $extra) {
            $order = PurchaseOrder::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->status === PurchaseOrderStatus::INVOICED || $order->status === PurchaseOrderStatus::CANCELLED) {
                throw new DomainException(__('Final purchase orders cannot be changed.'));
            }

            if ($status === PurchaseOrderStatus::CANCELLED && $order->goodsReceipts()->where('status', InventoryDocumentStatus::POSTED->value())->exists()) {
                throw new DomainException(__('This purchase order has posted goods receipts. Cancel them first.'));
            }

            if ($status !== PurchaseOrderStatus::CANCELLED && $order->items->isEmpty()) {
                throw new DomainException(__('Purchase order must have at least one item.'));
            }

            $order->update(array_merge(['status' => $status->value()], $extra));

            return $order->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product']);
        });
    }

    private function normalizeData(array $data, array $items = []): array
    {
        $total = round(array_sum(array_column($items, 'line_total')), 4);
        $orderDate = $data['order_date'] ?? now()->toDateString();
        $isDirect = ($data['delivery_type'] ?? null) === PurchaseOrderDeliveryType::DIRECT->value;

        if ($isDirect && blank($data['direct_customer_id'] ?? null)) {
            throw new DomainException(__('Select the customer the supplier ships to directly.'));
        }

        return [
            'client_id' => (int) $data['client_id'],
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : null,
            'delivery_type' => $isDirect ? PurchaseOrderDeliveryType::DIRECT->value : PurchaseOrderDeliveryType::WAREHOUSE->value,
            'direct_customer_id' => $isDirect ? (int) $data['direct_customer_id'] : null,
            'direct_sales_order_id' => $isDirect && filled($data['direct_sales_order_id'] ?? null) ? (int) $data['direct_sales_order_id'] : null,
            'order_date' => $orderDate,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            // لا عملة ولا ضريبة ولا خصم على الطلبية؛ مبالغها من اسعار سطورها.
            'currency_id' => null,
            'tax_id' => null,
            'exchange_rate' => 1,
            'sub_total' => $total,
            'items_discount' => 0,
            'order_discount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => $total,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => filled($item['product_id'] ?? null))
            ->map(function (array $item): array {
                $product = Product::query()->with('translations')->find($item['product_id']);
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);

                if ($quantity <= 0) {
                    throw new DomainException(__('Purchase order item quantity must be greater than zero.'));
                }

                $isWeightPriced = $product?->pricing_type === 'weight';
                $pieces = $isWeightPriced ? WeightPieces::normalize($item['pieces'] ?? []) : [];
                $totalWeight = round(collect($pieces)->sum('total_weight'), 4);
                $actualWeight = max($this->decimalValue($item['actual_weight'] ?? 0), 0);
                $unitPrice = $this->decimalValue($item['unit_price'] ?? 0);

                if ($unitPrice < 0) {
                    throw new DomainException(__('Purchase invoice item price cannot be negative.'));
                }

                // كسطر الفاتورة: صنف الوزن بوزنه الفعلي وصفر بدونه، وغيره بكميته.
                $lineTotal = round(($isWeightPriced ? $actualWeight : $quantity) * $unitPrice, 4);

                return [
                    'id' => $item['id'] ?? null,
                    'source_purchase_order_item_id' => $item['source_purchase_order_item_id'] ?? null,
                    'product_id' => (int) $item['product_id'],
                    // المجموعة التي يندرج تحتها الصنف في جدول الادخال، ان وُجدت.
                    'category_id' => filled($item['category_id'] ?? null) ? (int) $item['category_id'] : null,
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'description' => $item['description'] ?? $product?->name,
                    'quantity' => $quantity,
                    'length' => filled($item['length'] ?? null) ? $this->decimalValue($item['length']) : null,
                    'unit_price' => $unitPrice,
                    'discount_rate' => 0,
                    'discount_amount' => 0,
                    'line_total' => $lineTotal,
                    'total_weight' => $totalWeight,
                    'actual_weight' => $actualWeight ?: null,
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $item['created_by'] ?? auth()->id(),
                    'pieces' => $pieces,
                ];
            })
            ->values()
            ->toArray();
    }

    private function syncItems(PurchaseOrder $order, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->values();

        $removedItems = $order->items()
            ->when($incomingIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn ($query) => $query)
            ->get();

        foreach ($removedItems as $removedItem) {
            $removedItem->pieces()->delete();
            $removedItem->delete();
        }

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existingItem = $order->items()->whereKey($id)->first()) {
                $existingItem->update($item);
            } else {
                $existingItem = $order->items()->create($item);
            }

            WeightPieces::sync($existingItem->pieces(), $pieces);
        }
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}
