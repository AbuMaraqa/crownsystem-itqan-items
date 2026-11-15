<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;
use Modules\Items\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Items\Services\ProductBarcodeService;
use Modules\Items\Services\ProductPriceService;
use Modules\Items\Services\ProductUnitService;
use Modules\Items\Support\PriceResolver;

/**
 * وحدات الصنف وباركوداته واسعاره في قوائم الاسعار. التحقق كله في الخدمات (ProductUnitService،
 * ProductBarcodeService، ProductPriceService)، وهذا المتحكم يعرض ويمرر.
 */
class ProductUnitsAndPricesController extends Controller
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public function show(int $product): Response
    {
        /** @var Product $model */
        $model = $this->products->query()->with(['translations', 'unit.translations'])->findOrFail($product);
        $units = $model->productUnits()->with('unit.translations')->get();
        $lists = PriceList::query()->with('translations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $baseUnit = (string) ($model->unit?->name ?? __('Not set'));

        $unitRows = [['id' => 0, 'label' => $baseUnit.' ('.__('Base Unit').')'], ...$units->map(fn (ProductUnit $unit) => [
            'id' => (int) $unit->id,
            'label' => (string) $unit->unit?->name,
        ])->all()];

        $saved = ProductPrice::query()->where('product_id', $model->id)->get()
            ->keyBy(fn (ProductPrice $price): string => $price->price_list_id.':'.(int) $price->product_unit_id);

        $prices = [];
        $calculated = [];

        foreach ($lists as $list) {
            foreach ($unitRows as $row) {
                $price = $saved->get($list->id.':'.$row['id']);
                $prices[$list->id][$row['id']] = [
                    'sell_price' => $price?->sell_price ?? '',
                    'buy_price' => $price?->buy_price ?? '',
                    'source_price_list_id' => $price?->source_price_list_id,
                    'source_factor' => $price?->source_factor ?? '',
                ];

                // السعر بعد حساب المعادلة، بجانب الصف الذي له قائمة مصدر.
                if ($price?->source_price_list_id) {
                    $unit = $units->firstWhere('id', $row['id']);
                    $calculated[$list->id][$row['id']] = PriceResolver::resolve($model, $list, $unit, 'sell').' / '.PriceResolver::resolve($model, $list, $unit, 'buy');
                }
            }
        }

        return Inertia::render('Items/Product/UnitsAndPrices', [
            'product' => ['id' => $model->id, 'sku' => $model->sku, 'name' => (string) $model->name, 'barcode' => $model->barcode, 'base_unit' => $baseUnit],
            'units' => $units->map(fn (ProductUnit $unit) => [
                'id' => (int) $unit->id,
                'unit_id' => (int) $unit->unit_id,
                'unit' => (string) $unit->unit?->name,
                'factor' => self::trim($unit->factor),
            ])->values(),
            'barcodes' => $model->productBarcodes()->with('productUnit.unit.translations')->get()->map(fn (ProductBarcode $row) => [
                'id' => (int) $row->id,
                'barcode' => $row->barcode,
                'product_unit_id' => $row->product_unit_id,
                'unit' => (string) ($row->productUnit?->unit?->name ?? $baseUnit),
                'pack_quantity' => self::trim($row->pack_quantity),
                'is_prefix' => (bool) $row->is_prefix,
            ])->values(),
            'priceLists' => $lists->map(fn (PriceList $list) => ['id' => (int) $list->id, 'name' => (string) $list->name, 'same_buy_sell' => (bool) $list->same_buy_sell])->values(),
            'unitRows' => $unitRows,
            'prices' => (object) $prices,
            'calculated' => (object) $calculated,
            'unitOptions' => Unit::query()->with('translations')->get()->map(fn (Unit $unit) => ['value' => (int) $unit->id, 'label' => (string) $unit->name])->values(),
            // تكرار الباركود بين الاصناف جائز: تنبيه بعد الحفظ فقط.
            'notice' => session('barcode_notice'),
        ]);
    }

    public function storeUnit(Request $request, ProductUnitService $service, int $product): RedirectResponse
    {
        $service->save($this->product($product), $request->only(['unit_id', 'factor']));

        return back()->with('success', __('Unit saved successfully'));
    }

    public function updateUnit(Request $request, ProductUnitService $service, int $product, int $unit): RedirectResponse
    {
        $model = $this->product($product);

        $service->save($model, $request->only(['unit_id', 'factor']), $model->productUnits()->whereKey($unit)->firstOrFail());

        return back()->with('success', __('Unit saved successfully'));
    }

    public function destroyUnit(ProductUnitService $service, int $product, int $unit): RedirectResponse
    {
        $service->delete($this->product($product)->productUnits()->whereKey($unit)->firstOrFail());

        return back()->with('success', __('Unit deleted successfully'));
    }

    public function storeBarcode(Request $request, ProductBarcodeService $service, int $product): RedirectResponse
    {
        return $this->saveBarcode($request, $service, $this->product($product), null);
    }

    public function updateBarcode(Request $request, ProductBarcodeService $service, int $product, int $barcode): RedirectResponse
    {
        $model = $this->product($product);

        return $this->saveBarcode($request, $service, $model, $model->productBarcodes()->whereKey($barcode)->firstOrFail());
    }

    public function destroyBarcode(ProductBarcodeService $service, int $product, int $barcode): RedirectResponse
    {
        $service->delete($this->product($product)->productBarcodes()->whereKey($barcode)->firstOrFail());

        return back()->with('success', __('Barcode deleted successfully'));
    }

    public function savePrices(Request $request, ProductPriceService $service, int $product): RedirectResponse
    {
        $prices = $request->validate(['prices' => ['present', 'array'], 'prices.*' => ['array']])['prices'];

        $service->saveAll($this->product($product), $prices);

        return back()->with('success', __('Prices saved successfully'));
    }

    private function saveBarcode(Request $request, ProductBarcodeService $service, Product $product, ?ProductBarcode $row): RedirectResponse
    {
        $saved = $service->save($product, $request->only(['barcode', 'product_unit_id', 'pack_quantity', 'is_prefix']), $row);
        $redirect = back()->with('success', __('Barcode saved successfully'));

        if ($others = $service->otherItemsCount($product, $saved->barcode)) {
            $redirect->with('barcode_notice', __('This barcode also exists on :count other items.', ['count' => $others]));
        }

        return $redirect;
    }

    private function product(int $id): Product
    {
        /** @var Product */
        return $this->products->findOrFail($id);
    }

    private static function trim(mixed $value): string
    {
        return rtrim(rtrim((string) $value, '0'), '.');
    }
}
