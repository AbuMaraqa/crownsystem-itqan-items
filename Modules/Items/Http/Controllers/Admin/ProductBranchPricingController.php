<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Branch\Entities\Branch;
use Modules\Items\Entities\Product;
use Modules\Items\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Items\Services\ProductService;

/**
 * سعر الصنف ومتغيراته في كل فرع؛ السعر الفارغ يحذف سعر الفرع.
 */
class ProductBranchPricingController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductService $productService,
    ) {}

    public function show(int $product): Response
    {
        $model = $this->products->withVariations($product);
        $branches = Branch::query()->with('translations')->orderBy('id')->get();
        $rows = collect([$model, ...$model->variations]);
        $saved = $this->products->branchPrices($rows->pluck('id'));

        return Inertia::render('Items/Product/BranchPricings', [
            'product' => ['id' => $model->id, 'name' => (string) $model->name],
            'rows' => $rows->map(fn (Product $row) => ['id' => $row->id, 'name' => (string) $row->name])->values(),
            'branches' => $branches->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => (string) $branch->name])->values(),
            'prices' => (object) $rows->mapWithKeys(fn (Product $row) => [
                $row->id => (object) $branches->mapWithKeys(fn (Branch $branch) => [
                    $branch->id => $saved->get("{$row->id}:{$branch->id}")?->price ?? '',
                ])->all(),
            ])->all(),
        ]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $prices = $request->validate([
            'prices' => ['present', 'array'],
            'prices.*' => ['array'],
            'prices.*.*' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ], [], ['prices.*.*' => __('Price')])['prices'];

        $this->productService->saveBranchPrices($this->products->withVariations($product), $prices);

        return back()->with('success', __('Prices saved successfully'));
    }
}
