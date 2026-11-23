<?php

use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Modules\Accounting\Http\Controllers\Admin\Inventory\DeliveryNoteController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\GoodsReceiptController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\ItemStockCardController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\StockBalanceController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\StockFollowUpController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\StockTransferController;
use Modules\Accounting\Http\Controllers\Admin\Inventory\WarehouseLayoutController;
use Modules\Accounting\Http\Controllers\InventoryPrintController;
use Modules\Accounting\Support\StockLimits;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::group([
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => ['localeSessionRedirect', 'localizationRedirect', 'localeViewPath'],
    ], function () {
        Route::prefix('admin/accounting/inventory')
            ->as('accounting.inventory.')
            ->group(function () {
                Route::controller(WarehouseLayoutController::class)->prefix('layout')->as('layout')->group(function () {
                    Route::get('/products', 'products')->name('.products')->can('Warehouse Location Manage');
                    Route::post('/locations/{location}/place', 'place')->whereNumber('location')->name('.place')->can('Warehouse Location Manage');
                    Route::post('/locations/{location}/toggle', 'toggle')->whereNumber('location')->name('.toggle')->can('Warehouse Location Manage');
                    Route::delete('/locations/{location}', 'destroyLocation')->whereNumber('location')->name('.locations.destroy')->can('Warehouse Location Manage');
                    Route::delete('/placements/{placement}', 'unplace')->whereNumber('placement')->name('.unplace')->can('Warehouse Location Manage');
                    Route::post('/{warehouse}/generate', 'generate')->whereNumber('warehouse')->name('.generate')->can('Warehouse Location Manage');
                    Route::post('/{warehouse}/import-shelves', 'importShelves')->whereNumber('warehouse')->name('.import-shelves')->can('Warehouse Location Manage');
                    Route::get('/{warehouse?}', 'index')->whereNumber('warehouse')->name('')->can('Warehouse Location View List');
                });

                Route::controller(StockBalanceController::class)->prefix('stock')->as('stock.')->group(function () {
                    Route::get('/', 'index')->name('index')->can('Stock View List');
                    Route::get('/main-products', 'mainProducts')->name('main-products')->can('Stock View List');
                });

                Route::controller(ItemStockCardController::class)->prefix('items')->as('item-card')->group(function () {
                    Route::get('/search', 'search')->name('.search')->can('Stock Item Card View');
                    Route::post('/{product}/locations', 'assign')->whereNumber('product')->name('.locations.assign')->can('Warehouse Location Manage');
                    Route::post('/{product}/locations/{placement}/primary', 'makePrimary')->whereNumber(['product', 'placement'])->name('.locations.primary')->can('Warehouse Location Manage');
                    Route::delete('/{product}/locations/{placement}', 'unassign')->whereNumber(['product', 'placement'])->name('.locations.remove')->can('Warehouse Location Manage');
                    Route::get('/{product?}', 'index')->whereNumber('product')->name('')->can('Stock Item Card View');
                });

                // قسم المتابعة: صفحة واحدة، ولكل فئة رابطها وصلاحيتها.
                Route::controller(StockFollowUpController::class)->prefix('follow-up')->as('follow-up.')->group(function () {
                    Route::get('/aluminum', 'index')->name('aluminum')->defaults('category', 'aluminum')->can('Aluminum Stock Follow-up View List');
                    Route::get('/parts', 'index')->name('parts')->defaults('category', 'parts')->can('Parts Stock Follow-up View List');
                    Route::put('/aluminum/items/{product}/limits', 'saveLimits')->whereNumber('product')->name('aluminum.limits')->defaults('category', 'aluminum')->can(StockLimits::PERMISSION);
                    Route::put('/parts/items/{product}/limits', 'saveLimits')->whereNumber('product')->name('parts.limits')->defaults('category', 'parts')->can(StockLimits::PERMISSION);
                });

                Route::controller(GoodsReceiptController::class)->prefix('goods-receipts')->as('goods-receipts.')->group(function () {
                    Route::get('/', 'index')->name('index')->can('Goods Receipt View List');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Goods Receipt Delete');
                    Route::get('/add', 'add')->name('add')->can('Goods Receipt Create');
                    Route::post('/', 'store')->name('store')->can('Goods Receipt Create');
                    Route::get('/orders', 'orders')->name('orders')->can('Goods Receipt Create');
                    Route::get('/warehouse', 'warehouse')->name('warehouse')->can('Goods Receipt View List');
                    Route::get('/products', 'products')->name('products')->can('Goods Receipt View List');
                    Route::post('/value', 'preview')->name('value')->can('Stock Value View');
                    Route::get('/{goodsReceipt}', 'edit')->whereNumber('goodsReceipt')->name('edit')->can('Goods Receipt View List');
                    Route::put('/{goodsReceipt}', 'update')->whereNumber('goodsReceipt')->name('update')->can('Goods Receipt Update');
                    Route::post('/{goodsReceipt}/post', 'post')->whereNumber('goodsReceipt')->name('post')->can('Goods Receipt Post');
                    Route::post('/{goodsReceipt}/cancel', 'cancel')->whereNumber('goodsReceipt')->name('cancel')->can('Goods Receipt Cancel');
                    Route::delete('/{goodsReceipt}', 'destroy')->whereNumber('goodsReceipt')->name('destroy')->can('Goods Receipt Delete');
                    Route::get('/{goodsReceipt}/info', 'info')->whereNumber('goodsReceipt')->name('info')->can('Goods Receipt View List');
                    Route::get('/{goodsReceipt}/pdf', [GoodsReceiptController::class, 'pdf'])->whereNumber('goodsReceipt')->name('pdf')->can('Goods Receipt View List');
                });

                Route::controller(DeliveryNoteController::class)->prefix('delivery-notes')->as('delivery-notes.')->group(function () {
                    Route::get('/', 'index')->name('index')->can('Delivery Note View List');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Delivery Note Delete');
                    Route::get('/add', 'add')->name('add')->can('Delivery Note Create');
                    Route::post('/', 'store')->name('store')->can('Delivery Note Create');
                    Route::get('/orders', 'orders')->name('orders')->can('Delivery Note Create');
                    Route::get('/warehouse', 'warehouse')->name('warehouse')->can('Delivery Note View List');
                    Route::get('/products', 'products')->name('products')->can('Delivery Note View List');
                    Route::post('/available', 'available')->name('available')->can('Delivery Note View List');
                    Route::get('/{deliveryNote}', 'edit')->whereNumber('deliveryNote')->name('edit')->can('Delivery Note View List');
                    Route::put('/{deliveryNote}', 'update')->whereNumber('deliveryNote')->name('update')->can('Delivery Note Update');
                    Route::post('/{deliveryNote}/post', 'post')->whereNumber('deliveryNote')->name('post')->can('Delivery Note Post');
                    Route::post('/{deliveryNote}/cancel', 'cancel')->whereNumber('deliveryNote')->name('cancel')->can('Delivery Note Cancel');
                    Route::delete('/{deliveryNote}', 'destroy')->whereNumber('deliveryNote')->name('destroy')->can('Delivery Note Delete');
                    Route::get('/{deliveryNote}/info', 'info')->whereNumber('deliveryNote')->name('info')->can('Delivery Note View List');
                    Route::get('/{deliveryNote}/pdf', [DeliveryNoteController::class, 'pdf'])->whereNumber('deliveryNote')->name('pdf')->can('Delivery Note View List');
                });

                Route::controller(StockTransferController::class)->prefix('transfers')->as('transfers.')->group(function () {
                    Route::get('/', 'index')->name('index')->can('Stock Transfer View List');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Stock Transfer Delete');
                    Route::get('/add', 'add')->name('add')->can('Stock Transfer Create');
                    Route::post('/', 'store')->name('store')->can('Stock Transfer Create');
                    Route::get('/warehouse', 'warehouse')->name('warehouse')->can('Stock Transfer View List');
                    Route::get('/products', 'products')->name('products')->can('Stock Transfer View List');
                    Route::get('/line', 'line')->name('line')->can('Stock Transfer View List');
                    Route::get('/{stockTransfer}', 'edit')->whereNumber('stockTransfer')->name('edit')->can('Stock Transfer View List');
                    Route::put('/{stockTransfer}', 'update')->whereNumber('stockTransfer')->name('update')->can('Stock Transfer Update');
                    Route::post('/{stockTransfer}/post', 'post')->whereNumber('stockTransfer')->name('post')->can('Stock Transfer Post');
                    Route::post('/{stockTransfer}/cancel', 'cancel')->whereNumber('stockTransfer')->name('cancel')->can('Stock Transfer Cancel');
                    Route::delete('/{stockTransfer}', 'destroy')->whereNumber('stockTransfer')->name('destroy')->can('Stock Transfer Delete');
                    Route::get('/{stockTransfer}/info', 'info')->whereNumber('stockTransfer')->name('info')->can('Stock Transfer View List');
                    Route::get('/{stockTransfer}/pdf', [StockTransferController::class, 'pdf'])->whereNumber('stockTransfer')->name('pdf')->can('Stock Transfer View List');
                });

                Route::controller(StockAdjustmentController::class)->prefix('adjustments')->as('adjustments.')->group(function () {
                    Route::get('/', 'index')->name('index')->can('Stock Adjustment View List');
                    Route::delete('/', 'destroyMany')->name('destroy-many')->can('Stock Adjustment Delete');
                    Route::get('/add', 'add')->name('add')->can('Stock Adjustment Create');
                    Route::post('/', 'store')->name('store')->can('Stock Adjustment Create');
                    Route::get('/warehouse', 'warehouse')->name('warehouse')->can('Stock Adjustment View List');
                    Route::get('/products', 'products')->name('products')->can('Stock Adjustment View List');
                    Route::get('/line', 'line')->name('line')->can('Stock Adjustment View List');
                    Route::get('/count-sheet', 'countSheet')->name('count-sheet')->can('Stock Adjustment View List');
                    Route::get('/main-products', 'mainProducts')->name('main-products')->can('Stock Adjustment View List');
                    Route::get('/{stockAdjustment}', 'edit')->whereNumber('stockAdjustment')->name('edit')->can('Stock Adjustment View List');
                    Route::put('/{stockAdjustment}', 'update')->whereNumber('stockAdjustment')->name('update')->can('Stock Adjustment Update');
                    Route::post('/{stockAdjustment}/post', 'post')->whereNumber('stockAdjustment')->name('post')->can('Stock Adjustment Post');
                    Route::post('/{stockAdjustment}/cancel', 'cancel')->whereNumber('stockAdjustment')->name('cancel')->can('Stock Adjustment Cancel');
                    Route::delete('/{stockAdjustment}', 'destroy')->whereNumber('stockAdjustment')->name('destroy')->can('Stock Adjustment Delete');
                    Route::get('/{stockAdjustment}/info', 'info')->whereNumber('stockAdjustment')->name('info')->can('Stock Adjustment View List');
                    Route::get('/{stockAdjustment}/pdf', [StockAdjustmentController::class, 'pdf'])->whereNumber('stockAdjustment')->name('pdf')->can('Stock Adjustment View List');
                });

                Route::controller(InventoryPrintController::class)->prefix('print')->as('print.')->group(function () {
                    Route::get('/stock-report', 'stockReport')->name('stock-report')->can('Stock View List');
                    Route::get('/shelf-labels', 'shelfLabels')->name('shelf-labels')->can('Shelf Label Print');
                });
            });
    });
});
