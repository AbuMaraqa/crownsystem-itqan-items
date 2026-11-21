<?php

use Illuminate\Support\Facades\Route;
use Modules\Items\Http\Controllers\Admin\AddonController;
use Modules\Items\Http\Controllers\Admin\AttributeController;
use Modules\Items\Http\Controllers\Admin\BrandController;
use Modules\Items\Http\Controllers\Admin\CategoryController;
use Modules\Items\Http\Controllers\Admin\ItemTypeProfileController;
use Modules\Items\Http\Controllers\Admin\LabelController;
use Modules\Items\Http\Controllers\Admin\ManufacturerController;
use Modules\Items\Http\Controllers\Admin\OfferController;
use Modules\Items\Http\Controllers\Admin\PriceListController;
use Modules\Items\Http\Controllers\Admin\TagController;
use Modules\Items\Http\Controllers\Admin\UnitController;
use Modules\Items\Http\Controllers\Admin\CategoryTreeController;
use Modules\Items\Http\Controllers\Admin\ItemTreeController;
use Modules\Items\Http\Controllers\Admin\OrderController;
use Modules\Items\Http\Controllers\Admin\ProductBranchPricingController;
use Modules\Items\Http\Controllers\Admin\ProductController;
use Modules\Items\Http\Controllers\Admin\ProductUnitsAndPricesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::group(
        [
            'prefix' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale(),
            'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath']
        ],
        function () {
            Route::group([
                'prefix' => 'admin',
                'as' => '',
            ], function () {
                // صفحات Inertia (لوحة malbo).
                Route::prefix('categories')->name('categories.')->controller(CategoryController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Category View List');
                    Route::get('/add', 'add')->name('add')->can('Category Create');
                    Route::post('/', 'store')->name('store')->can('Category Create');
                    Route::post('/quick', 'quickStore')->name('quick-store')->can('Category Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Category Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Category Update');
                    Route::get('/{category}/edit', 'edit')->whereNumber('category')->name('edit')->can('Category Update');
                    Route::put('/{category}', 'update')->whereNumber('category')->name('update')->can('Category Update');
                    Route::delete('/{category}', 'destroy')->whereNumber('category')->name('destroy')->can('Category Delete');
                    Route::get('/{category}/info', 'info')->whereNumber('category')->name('info')->can('Category View List');
                });

                Route::prefix('attributes')->name('attributes.')->controller(AttributeController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Attribute View List');
                    Route::get('/add', 'add')->name('add')->can('Attribute Create');
                    Route::post('/', 'store')->name('store')->can('Attribute Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Attribute Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Attribute Update');
                    Route::get('/{attribute}/edit', 'edit')->whereNumber('attribute')->name('edit')->can('Attribute Update');
                    Route::put('/{attribute}', 'update')->whereNumber('attribute')->name('update')->can('Attribute Update');
                    Route::delete('/{attribute}', 'destroy')->whereNumber('attribute')->name('destroy')->can('Attribute Delete');
                    Route::get('/{attribute}/info', 'info')->whereNumber('attribute')->name('info')->can('Attribute View List');
                });

                Route::prefix('tags')->name('tags.')->controller(TagController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Tag View List');
                    Route::post('/', 'store')->name('store')->can('Tag Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Tag Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Tag Update');
                    Route::get('/{tag}/info', 'info')->whereNumber('tag')->name('info')->can('Tag View List');
                    Route::put('/{tag}', 'update')->whereNumber('tag')->name('update')->can('Tag Update');
                    Route::delete('/{tag}', 'destroy')->whereNumber('tag')->name('destroy')->can('Tag Delete');
                });

                Route::prefix('labels')->name('labels.')->controller(LabelController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Label View List');
                    Route::post('/', 'store')->name('store')->can('Label Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Label Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Label Update');
                    Route::get('/{label}/info', 'info')->whereNumber('label')->name('info')->can('Label View List');
                    Route::put('/{label}', 'update')->whereNumber('label')->name('update')->can('Label Update');
                    Route::delete('/{label}', 'destroy')->whereNumber('label')->name('destroy')->can('Label Delete');
                });

                // الرابط يبقى /brand والاسماء brands.*
                Route::prefix('brand')->name('brands.')->controller(BrandController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Brand View List');
                    Route::post('/', 'store')->name('store')->can('Brand Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Brand Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Brand Update');
                    Route::get('/{brand}/info', 'info')->whereNumber('brand')->name('info')->can('Brand View List');
                    Route::put('/{brand}', 'update')->whereNumber('brand')->name('update')->can('Brand Update');
                    Route::delete('/{brand}', 'destroy')->whereNumber('brand')->name('destroy')->can('Brand Delete');
                });

                Route::prefix('manufacturers')->name('manufacturers.')->controller(ManufacturerController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Manufacturer View List');
                    Route::post('/', 'store')->name('store')->can('Manufacturer Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Manufacturer Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Manufacturer Update');
                    Route::get('/{manufacturer}/info', 'info')->whereNumber('manufacturer')->name('info')->can('Manufacturer View List');
                    Route::put('/{manufacturer}', 'update')->whereNumber('manufacturer')->name('update')->can('Manufacturer Update');
                    Route::delete('/{manufacturer}', 'destroy')->whereNumber('manufacturer')->name('destroy')->can('Manufacturer Delete');
                });

                Route::prefix('units')->name('units.')->controller(UnitController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Unit View List');
                    Route::post('/', 'store')->name('store')->can('Unit Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Unit Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Unit Update');
                    Route::get('/{unit}/info', 'info')->whereNumber('unit')->name('info')->can('Unit View List');
                    Route::put('/{unit}', 'update')->whereNumber('unit')->name('update')->can('Unit Update');
                    Route::delete('/{unit}', 'destroy')->whereNumber('unit')->name('destroy')->can('Unit Delete');
                });

                Route::prefix('item-type-profiles')->name('item-type-profiles.')->controller(ItemTypeProfileController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Item Type Profile View List');
                    Route::post('/', 'store')->name('store')->can('Item Type Profile Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Item Type Profile Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Item Type Profile Update');
                    Route::put('/{profile}', 'update')->whereNumber('profile')->name('update')->can('Item Type Profile Update');
                    Route::delete('/{profile}', 'destroy')->whereNumber('profile')->name('destroy')->can('Item Type Profile Delete');
                    Route::get('/{profile}/info', 'info')->whereNumber('profile')->name('info')->can('Item Type Profile View List');
                });

                Route::prefix('price-lists')->name('price-lists.')->controller(PriceListController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Price List View List');
                    Route::post('/', 'store')->name('store')->can('Price List Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Price List Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Price List Update');
                    Route::put('/{priceList}', 'update')->whereNumber('priceList')->name('update')->can('Price List Update');
                    Route::delete('/{priceList}', 'destroy')->whereNumber('priceList')->name('destroy')->can('Price List Delete');
                    Route::get('/{priceList}/info', 'info')->whereNumber('priceList')->name('info')->can('Price List View List');
                });

                Route::prefix('offers')->name('offers.')->controller(OfferController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Offer View List');
                    Route::get('/add', 'add')->name('add')->can('Offer Create');
                    Route::post('/', 'store')->name('store')->can('Offer Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Offer Delete');
                    // منتقي الهدف (?model=items_category|items_product&branches[]=..&q=)، مسار لكل نموذج.
                    Route::get('/targets', 'targets')->name('targets')->can('Offer Create');
                    Route::get('/edit-targets', 'targets')->name('edit-targets')->can('Offer Update');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Offer Update');
                    Route::get('/{offer}/edit', 'edit')->whereNumber('offer')->name('edit')->can('Offer Update');
                    Route::put('/{offer}', 'update')->whereNumber('offer')->name('update')->can('Offer Update');
                    Route::delete('/{offer}', 'destroy')->whereNumber('offer')->name('destroy')->can('Offer Delete');
                    Route::get('/{offer}/info', 'info')->whereNumber('offer')->name('info')->can('Offer View List');
                });

                Route::prefix('addons')->name('addons.')->controller(AddonController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Addon View List');
                    Route::get('/add', 'add')->name('add')->can('Addon Create');
                    Route::post('/', 'store')->name('store')->can('Addon Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Addon Delete');
                    Route::post('/reorder', 'reorder')->name('reorder')->can('Addon Update');
                    Route::get('/{addon}/edit', 'edit')->whereNumber('addon')->name('edit')->can('Addon Update');
                    Route::put('/{addon}', 'update')->whereNumber('addon')->name('update')->can('Addon Update');
                    Route::delete('/{addon}', 'destroy')->whereNumber('addon')->name('destroy')->can('Addon Delete');
                    Route::get('/{addon}/info', 'info')->whereNumber('addon')->name('info')->can('Addon View List');
                });

                // شجرة التصنيفات وصفحة اصناف التصنيف.
                Route::prefix('categories')->name('categories.')->controller(CategoryTreeController::class)->group(function () {
                    Route::get('/tree', 'index')->name('tree')->can('Category View List');
                    Route::get('/tree/{category}/children', 'children')->whereNumber('category')->name('tree.children')->can('Category View List');
                    Route::put('/tree/{category}/move', 'move')->whereNumber('category')->name('tree.move')->can('Category Update');
                    Route::put('/tree/{category}/status', 'toggleStatus')->whereNumber('category')->name('tree.status')->can('Category Update');
                    Route::get('/{category}/products', 'products')->whereNumber('category')->name('products')->can('Product View List');
                });

                // شجرة الاصناف (Itqan): الفروع تُحمَّل عند فتحها، والقائمة الجانبية بالصلاحيات.
                Route::prefix('products/tree')->name('products.tree')->controller(ItemTreeController::class)->group(function () {
                    Route::get('/', 'index')->name('')->can('Product View List');
                    Route::get('/children/{category}', 'children')->whereNumber('category')->name('.children')->can('Product View List');
                    Route::get('/pieces/{product}', 'pieces')->whereNumber('product')->name('.pieces')->can('Product View List');
                    Route::get('/path/{category}', 'path')->whereNumber('category')->name('.path')->can('Product View List');
                    Route::get('/rows', 'rows')->name('.rows')->can('Product View List');
                    Route::get('/quantity/{product}', 'quantity')->whereNumber('product')->name('.quantity')->can('Product View List');
                    Route::get('/actions', 'actions')->name('.actions')->can('Product View List');
                    Route::get('/lookup', 'lookup')->name('.lookup')->can('Product View List');
                    Route::get('/move-targets', 'moveTargets')->name('.move-targets')->can('Product View List');
                    Route::get('/items/new', 'createItem')->name('.items.create')->can('Product Create');
                    Route::post('/items', 'storeItem')->name('.items.store')->can('Product Create');
                    Route::get('/items/{product}', 'editItem')->whereNumber('product')->name('.items.edit')->can('Product Update');
                    Route::put('/items/{product}', 'updateItem')->whereNumber('product')->name('.items.update')->can('Product Update');
                    Route::post('/items/{product}/copy', 'copy')->whereNumber('product')->name('.items.copy')->can('Product Create');
                    Route::put('/items/{product}/move', 'moveItem')->whereNumber('product')->name('.items.move')->can('Product Update');
                    Route::delete('/items/{product}', 'destroyItem')->whereNumber('product')->name('.items.destroy')->can('Product Delete');
                    Route::post('/categories', 'storeCategory')->name('.categories.store')->can('Category Create');
                    Route::get('/categories/{category}', 'showCategory')->whereNumber('category')->name('.categories.show')->can('Category Update');
                    Route::put('/categories/{category}', 'updateCategory')->whereNumber('category')->name('.categories.update')->can('Category Update');
                    Route::put('/categories/{category}/move', 'moveCategory')->whereNumber('category')->name('.categories.move')->can('Category Update');
                    Route::delete('/categories/{category}', 'destroyCategory')->whereNumber('category')->name('.categories.destroy')->can('Category Delete');
                });

                // بطاقة الصنف.
                Route::prefix('products')->name('products.')->controller(ProductController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Product View List');
                    Route::get('/add', 'add')->name('add')->can('Product Create');
                    Route::post('/', 'store')->name('store')->can('Product Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Product Delete');
                    // منتقي الاصناف في النموذج (?type=main|goods|any&exclude=..&q=)، مسار لكل نموذج.
                    Route::get('/lookup', 'lookup')->name('lookup')->can('Product Create');
                    Route::get('/edit-lookup', 'lookup')->name('edit-lookup')->can('Product Update');
                    Route::get('/{product}/edit', 'edit')->whereNumber('product')->name('edit')->can('Product Update');
                    Route::put('/{product}', 'update')->whereNumber('product')->name('update')->can('Product Update');
                    Route::put('/{product}/active', 'active')->whereNumber('product')->name('active')->can('Product Update');
                    Route::delete('/{product}', 'destroy')->whereNumber('product')->name('destroy')->can('Product Delete');
                    Route::get('/{product}/info', 'info')->whereNumber('product')->name('info')->can('Product View List');
                    Route::get('/{product}/barcodes', 'barcodes')->whereNumber('product')->name('barcodes')->can('Product View List');
                    Route::get('/{product}/barcodes/pdf', 'barcodesPdf')->whereNumber('product')->name('barcodes.pdf')->can('Product View List');
                });

                Route::prefix('products/{product}')->whereNumber('product')->name('products.')->group(function () {
                    Route::controller(ProductBranchPricingController::class)->group(function () {
                        Route::get('/branch-pricings', 'show')->name('branch-pricings')->can('Product Branch Pricings');
                        Route::put('/branch-pricings', 'update')->name('branch-pricings.update')->can('Product Branch Pricings');
                    });

                    Route::prefix('units-and-prices')->name('units-and-prices')->controller(ProductUnitsAndPricesController::class)->group(function () {
                        Route::get('/', 'show')->name('')->can('Product Units And Prices');
                        Route::post('/units', 'storeUnit')->name('.units.store')->can('Product Units And Prices');
                        Route::put('/units/{unit}', 'updateUnit')->whereNumber('unit')->name('.units.update')->can('Product Units And Prices');
                        Route::delete('/units/{unit}', 'destroyUnit')->whereNumber('unit')->name('.units.destroy')->can('Product Units And Prices');
                        Route::post('/barcodes', 'storeBarcode')->name('.barcodes.store')->can('Product Units And Prices');
                        Route::put('/barcodes/{barcode}', 'updateBarcode')->whereNumber('barcode')->name('.barcodes.update')->can('Product Units And Prices');
                        Route::delete('/barcodes/{barcode}', 'destroyBarcode')->whereNumber('barcode')->name('.barcodes.destroy')->can('Product Units And Prices');
                        Route::put('/prices', 'savePrices')->name('.prices.update')->can('Product Units And Prices');
                    });
                });

                Route::prefix('orders')->name('orders.')->controller(OrderController::class)->group(function () {
                    Route::get('/', 'index')->name('index')->can('Order View List');
                    Route::get('/add', 'add')->name('add')->can('Order Create');
                    Route::post('/', 'store')->name('store')->can('Order Create');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Order Delete');
                    Route::get('/lookup/{type}', 'lookup')->whereIn('type', ['customers', 'products', 'cities', 'districts'])->name('lookup')->can('Order Create');
                    Route::get('/edit-lookup/{type}', 'lookup')->whereIn('type', ['customers', 'products', 'cities', 'districts'])->name('edit-lookup')->can('Order Update');
                    Route::get('/{order}/info', 'info')->whereNumber('order')->name('info')->can('Order View List');
                    Route::get('/{order}/edit', 'edit')->whereNumber('order')->name('edit')->can('Order Update');
                    Route::put('/{order}', 'update')->whereNumber('order')->name('update')->can('Order Update');
                    Route::delete('/{order}', 'destroy')->whereNumber('order')->name('destroy')->can('Order Delete');
                });
            });
        }
    );
});
