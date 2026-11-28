<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Console\Commands\BackfillUserAccounts;
use Modules\Accounting\Console\Commands\RebuildAccountBalances;
use Modules\Accounting\Console\Commands\SwitchBaseCurrency;
use Modules\Accounting\Entities;
use Modules\Accounting\Observers\DocumentNotificationObserver;
use Modules\Core\Support\Search\SearchIndex;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AccountingServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Accounting';

    protected string $nameLower = 'accounting';

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        DocumentNotificationObserver::register();
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(RepositoryServiceProvider::class);
        $this->app->register(SidebarServiceProvider::class);

        // المستندات المفهرسة في محرك البحث (اعمدتها في searchColumns() بكل موديل).
        SearchIndex::register(
            Entities\SalesQuotation::class,
            Entities\SalesOrder::class,
            Entities\SalesInvoice::class,
            Entities\SalesReturn::class,
            Entities\PurchaseQuotation::class,
            Entities\PurchaseOrder::class,
            Entities\PurchaseInvoice::class,
            Entities\PurchaseReturn::class,
            Entities\ConsolidatedPurchaseOrder::class,
            Entities\ReceiptVoucher::class,
            Entities\PaymentVoucher::class,
            Entities\Cheque::class,
            Entities\JournalEntry::class,
            Entities\PeriodClosingVoucher::class,
            Entities\GoodsReceipt::class,
            Entities\DeliveryNote::class,
            Entities\StockTransfer::class,
            Entities\StockAdjustment::class,
            Entities\ProductionOrder::class,
        );
    }

    protected function registerCommands(): void
    {
        $this->commands([
            BackfillUserAccounts::class,
            RebuildAccountBalances::class,
            SwitchBaseCurrency::class,
        ]);
    }

    protected function registerCommandSchedules(): void
    {
        //
    }

    public function registerTranslations(): void
    {
        $langPath = module_path($this->name, 'Lang');

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, ''), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, ''));
        }
    }

    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (! is_dir($configPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $configKey = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
            $segments = explode('.', $this->nameLower.'.'.$configKey);

            $normalized = [];
            foreach ($segments as $segment) {
                if (end($normalized) !== $segment) {
                    $normalized[] = $segment;
                }
            }

            $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

            $this->publishes([$file->getPathname() => config_path($config)], 'config');
            $this->mergeConfigFromRecursive($file->getPathname(), $key);
        }
    }

    protected function mergeConfigFromRecursive(string $path, string $key): void
    {
        $existing = config($key, []);
        $moduleConfig = require $path;

        config([$key => array_replace_recursive($existing, $moduleConfig)]);
    }

    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);
        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }

    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];

        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
