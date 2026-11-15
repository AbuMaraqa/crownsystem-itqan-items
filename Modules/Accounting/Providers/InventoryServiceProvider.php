<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Console\Commands\RebuildStockAlerts;
use Modules\Accounting\Console\Commands\SetupInventoryAccounts;
use Modules\Accounting\Observers\StockLimitObserver;
use Modules\Items\Entities\Product;
use Modules\Core\Services\SidebarGroup;
use Modules\Core\Services\SidebarItem;
use Modules\Core\Services\SidebarService;

/**
 * المخازن: مسارات الصفحات وترجماتها وقائمتها الجانبية في ملفات خاصة بها،
 * فلا تتداخل مع ملفات المحاسبة العامة.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(module_path('Accounting', 'Lang/inventory'));

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(module_path('Accounting', 'routes/inventory.php'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SetupInventoryAccounts::class, RebuildStockAlerts::class]);
        }

        Product::observe(StockLimitObserver::class);

        $this->app->make(SidebarService::class)->add(
            (new SidebarGroup('Warehouses & Stock', 'solar-box-bold-duotone', [], 81))
                ->add(new SidebarItem('Aluminum Follow-up', 'solar-bell-bing-bold-duotone', ['Aluminum Stock Follow-up View List'], 'accounting.inventory.follow-up.aluminum'))
                ->add(new SidebarItem('Parts Follow-up', 'solar-bell-bing-bold-duotone', ['Parts Stock Follow-up View List'], 'accounting.inventory.follow-up.parts'))
                ->add(new SidebarItem('Stock Balances', 'solar-chart-square-bold-duotone', ['Stock View List'], 'accounting.inventory.stock.index'))
                ->add(new SidebarItem('Item Stock Card', 'solar-card-search-bold-duotone', ['Stock Item Card View'], 'accounting.inventory.item-card'))
                ->add(new SidebarItem('Goods Receipts', 'solar-inbox-in-bold-duotone', ['Goods Receipt View List'], 'accounting.inventory.goods-receipts.index'))
                ->add(new SidebarItem('Delivery Notes', 'solar-delivery-bold-duotone', ['Delivery Note View List'], 'accounting.inventory.delivery-notes.index'))
                ->add(new SidebarItem('Stock Transfers', 'solar-transfer-horizontal-bold-duotone', ['Stock Transfer View List'], 'accounting.inventory.transfers.index'))
                ->add(new SidebarItem('Stock Adjustments', 'solar-clipboard-check-bold-duotone', ['Stock Adjustment View List'], 'accounting.inventory.adjustments.index'))
                ->add(new SidebarItem('Warehouse Layout', 'solar-map-bold-duotone', ['Warehouse Location View List'], 'accounting.inventory.layout'))
                ->add(new SidebarItem('Warehouses', 'solar-buildings-2-bold-duotone', ['Warehouse View List'], 'accounting.warehouses.index'))
        );
    }
}
