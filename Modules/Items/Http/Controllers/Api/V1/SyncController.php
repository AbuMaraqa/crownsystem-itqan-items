<?php

namespace Modules\Items\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\AddonOption;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Offer;
use Modules\Items\Entities\Order;
use Modules\Items\Entities\Product;
use Modules\Items\Transformers\V1\AddonOptionResource;
use Modules\Items\Transformers\V1\AddonResource;
use Modules\Items\Transformers\V1\CategoryResource;
use Modules\Items\Transformers\V1\OfferResource;
use Modules\Items\Transformers\V1\OrderResource;
use Modules\Items\Transformers\V1\ProductResource;

class SyncController extends Controller
{
    public function syncProducts(Request $request)
    {
        $since = $request->input('since', '1970-01-01 00:00:00');

        $products = Product::query()
            ->with([
                'media',
                'translations',
                'categories.media',
                'categories.translations',
                'addons.media',
                'addons.translations',
                'addons.addonOptions.media',
                'addons.addonOptions.translations',
            ])
            ->withExists('variations')
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        $this->hydrateAddonCustomOptions($products);

        return ProductResource::collection($products)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    public function syncCategories(Request $request)
    {
        $since = $request->input('since', '1970-01-01 00:00:00');

        $categories = Category::query()
            ->with(['media', 'translations'])
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        return CategoryResource::collection($categories)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    public function syncAddons(Request $request){
        $since = $request->input('since', '1970-01-01 00:00:00');

        $addons = Addon::query()
            ->with([
                'media',
                'translations',
                'addonOptions.media',
                'addonOptions.translations',
            ])
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        return AddonResource::collection($addons)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    public function syncAddonsOptions(Request $request){
        $since = $request->input('since', '1970-01-01 00:00:00');

        $addonsOptions = AddonOption::query()
            ->with(['media', 'translations'])
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        return AddonOptionResource::collection($addonsOptions)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    public function syncOffers(Request $request){
        $since = $request->input('since', '1970-01-01 00:00:00');

        $offers = Offer::query()
            ->with(['media', 'translations'])
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        return OfferResource::collection($offers)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    public function syncOrders(Request $request){
        $since = $request->input('since', '1970-01-01 00:00:00');

        $orders = Order::query()
            ->with([
                'user',
                'items.product.media',
                'items.product.translations',
                'items.addonOptions.media',
                'items.addonOptions.translations',
            ])
            ->where('updated_at', '>', $since)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        return OrderResource::collection($orders)
            ->additional([
                'meta' => [
                    'timestamp' => now()->toDateTimeString(),
                ]
            ]);
    }

    private function hydrateAddonCustomOptions(Collection $products): void
    {
        $addons = $products->flatMap(function (Product $product) {
            return $product->relationLoaded('addons') ? $product->addons : collect();
        });

        $pivotIds = $addons
            ->pluck('pivot.id')
            ->filter()
            ->unique()
            ->values();

        if ($pivotIds->isEmpty()) {
            return;
        }

        $customOptions = DB::table(config('items.table_prefix') . 'addon_product_option')
            ->whereIn('addon_product_id', $pivotIds)
            ->get()
            ->groupBy('addon_product_id');

        $addons->each(function ($addon) use ($customOptions) {
            $addon->setAttribute(
                'custom_option_rows',
                $customOptions->get($addon->pivot->id, collect())
            );
        });
    }
}
