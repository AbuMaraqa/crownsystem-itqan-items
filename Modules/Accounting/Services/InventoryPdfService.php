<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Support\StockStatus;
use Modules\Core\Services\PdfService;
use Modules\Core\Support\DashboardTheme;
use Modules\Items\Entities\Product;
use Modules\Items\Support\PriceGroupSearch;

/**
 * مطبوعات المخزن: بطاقات الرفوف، تقرير الارصدة بنوع الالمنيوم، والمستندات.
 */
class InventoryPdfService extends PdfService
{
    public function goodsReceipt(GoodsReceipt $receipt)
    {
        $receipt->loadMissing(['purchaseOrder', 'client', 'warehouse.translations', 'items.location', 'createdBy', 'postedBy']);

        return $this->streamPdf('accounting::pdf.inventory.goods-receipt', ['document' => $receipt], $receipt->reference_no.'.pdf');
    }

    public function deliveryNote(DeliveryNote $note)
    {
        $note->loadMissing(['salesOrder', 'client', 'warehouse.translations', 'items.location', 'createdBy', 'postedBy']);

        $placements = $note->isDraft()
            ? app(StockLevelService::class)->locations($note->items->pluck('product_id')->all(), $note->warehouse_id)
            : [];

        return $this->streamPdf('accounting::pdf.inventory.delivery-note', ['document' => $note, 'placements' => $placements], $note->reference_no.'.pdf');
    }

    public function stockTransfer(StockTransfer $transfer)
    {
        $transfer->loadMissing(['fromWarehouse.translations', 'toWarehouse.translations', 'items.fromLocation', 'items.toLocation', 'createdBy']);

        return $this->streamPdf('accounting::pdf.inventory.stock-transfer', ['document' => $transfer], $transfer->reference_no.'.pdf');
    }

    public function stockAdjustment(StockAdjustment $adjustment)
    {
        $adjustment->loadMissing(['warehouse.translations', 'items.location', 'createdBy']);

        return $this->streamPdf('accounting::pdf.inventory.stock-adjustment', ['document' => $adjustment], $adjustment->reference_no.'.pdf');
    }

    /**
     * تقرير ارصدة المخزن مجمّعاً بمجموعة السعر (التصنيف × اللون) كالتقرير الورقي؛ وزن المجموعة
     * مجموع اوزان ألوانها.
     */
    public function stockReport(?int $warehouseId, ?int $priceGroupId, ?string $status)
    {
        $warehouse = $warehouseId ? Warehouse::query()->with('translations')->find($warehouseId) : null;
        $balances = DB::table(config('accounting.table_prefix').'stock_balances')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(weight) as weight');
        $products = (new Product)->getTable();

        $query = Product::query()
            ->with(['translations', 'categories.translations', 'media'])
            ->joinSub($balances, 'balances', 'balances.product_id', '=', $products.'.id')
            ->select($products.'.*')
            ->selectRaw('balances.quantity as stock_on_hand, balances.weight as stock_weight')
            ->when($priceGroupId, fn ($q) => $q->where($products.'.category_finish_id', $priceGroupId))
            ->orderBy($products.'.category_finish_id')
            ->orderBy($products.'.barcode');

        StockStatus::applyFilter($query, $status);

        $items = $query->limit(5000)->get();
        $levels = app(StockLevelService::class);
        $ids = $items->pluck('id')->all();
        $reserved = $levels->reserved($ids, $warehouseId);
        $incoming = $levels->incoming($ids, $warehouseId);
        $locations = $warehouseId ? $levels->locations($ids, $warehouseId) : [];

        $groupLabels = PriceGroupSearch::labels($items->pluck('category_finish_id')->filter()->unique()->values()->all());
        $groups = $items->groupBy(fn (Product $product) => $groupLabels[(int) $product->category_finish_id] ?? __('Other Items'));

        return $this->streamPdf('accounting::pdf.inventory.stock-report', [
            'warehouse' => $warehouse,
            'groups' => $groups,
            'reserved' => $reserved,
            'incoming' => $incoming,
            'locations' => $locations,
            'statusLabel' => $status ? (StockStatus::options()[$status] ?? null) : null,
            'stockColors' => app(DashboardTheme::class)->stockColors(),
        ], __('Stock Report').'.pdf', ['orientation' => 'L', 'format' => 'A4-L']);
    }

    /**
     * بطاقات الرفوف كالتي على الاعمدة: الباركود، صورة المقطع، الاسم، الرمز، QR يفتح بطاقة
     * الصنف، والسهم نحو مكان الصنف.
     *
     * @param  array<int, int>  $productIds
     */
    public function shelfLabels(array $productIds, ?int $warehouseId, ?int $locationId)
    {
        $labels = $this->labels($productIds, $warehouseId, $locationId);

        return $this->streamPdf('accounting::pdf.inventory.shelf-labels', ['labels' => $labels], __('Shelf Labels').'.pdf', [
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 8,
            'margin_bottom' => 8,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function labels(array $productIds, ?int $warehouseId, ?int $locationId): Collection
    {
        $placements = ProductLocation::query()
            ->with('location')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($productIds !== [], fn ($q) => $q->whereIn('product_id', $productIds))
            ->orderByDesc('is_primary')
            ->get();

        if ($productIds === [] && ($warehouseId || $locationId)) {
            $productIds = $placements->where('is_primary', true)->pluck('product_id')->unique()->all();

            if ($locationId) {
                $productIds = $placements->pluck('product_id')->unique()->all();
            }
        }

        $byProduct = $placements->groupBy('product_id');

        return Product::query()
            ->with(['translations', 'media'])
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(fn (Product $product) => optional($byProduct->get($product->id)?->first()?->location)->code.'|'.$product->barcode)
            ->map(function (Product $product) use ($byProduct, $locationId) {
                $placement = $byProduct->get($product->id)?->first(fn ($p) => $locationId ? $p->location_id === $locationId : $p->is_primary)
                    ?? $byProduct->get($product->id)?->first();
                $media = $product->getFirstMedia('product');
                $url = route('accounting.inventory.item-card', array_filter(['product' => $product->id, 'warehouse' => $placement?->warehouse_id]));

                return [
                    'barcode' => $product->barcode ?: $product->sku,
                    'name' => trim(preg_replace('/\s*\bK\d{3,6}\b\s*/u', ' ', (string) $product->name)),
                    'code' => $this->profileCode($product),
                    'image' => $media && is_file($media->getPath()) ? $media->getPath() : null,
                    'location' => $placement?->location?->code ?? $product->shelf,
                    'arrow' => $placement?->direction?->arrow(),
                    'url' => $url,
                    // رمز QR صورةً (milon/barcode)؛ وسم <barcode type="QR"> في mPDF يحتاج حزمة غير مثبتة.
                    'qr' => 'data:image/png;base64,'.$this->qrCode->getBarcodePNG($url, 'QRCODE', 6, 6),
                ];
            })
            ->values();
    }

    /**
     * رمز البروفيل (مثل K6358): من الاسم ان وُجد، والا رقم الصنف.
     */
    private function profileCode(Product $product): ?string
    {
        if (preg_match('/\bK\d{3,6}\b/u', (string) $product->name, $matches)) {
            return $matches[0];
        }

        return $product->sku && $product->sku !== $product->barcode ? $product->sku : null;
    }
}
