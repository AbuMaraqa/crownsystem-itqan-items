<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Services\InventoryPdfService;

class InventoryPrintController extends Controller
{
    public function __construct(private readonly InventoryPdfService $pdf) {}

    public function goodsReceipt(GoodsReceipt $goodsReceipt)
    {
        return $this->pdf->goodsReceipt($goodsReceipt);
    }

    public function deliveryNote(DeliveryNote $deliveryNote)
    {
        return $this->pdf->deliveryNote($deliveryNote);
    }

    public function stockTransfer(StockTransfer $stockTransfer)
    {
        return $this->pdf->stockTransfer($stockTransfer);
    }

    public function stockAdjustment(StockAdjustment $stockAdjustment)
    {
        return $this->pdf->stockAdjustment($stockAdjustment);
    }

    public function stockReport(Request $request)
    {
        return $this->pdf->stockReport(
            $request->integer('warehouse') ?: null,
            $request->integer('main_product') ?: null,
            $request->string('status')->toString() ?: null,
        );
    }

    public function shelfLabels(Request $request)
    {
        $products = collect(explode(',', (string) $request->query('products', '')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->take(1000)
            ->values()
            ->all();

        abort_if($products === [] && ! $request->integer('warehouse') && ! $request->integer('location'), 404);

        return $this->pdf->shelfLabels($products, $request->integer('warehouse') ?: null, $request->integer('location') ?: null);
    }
}
