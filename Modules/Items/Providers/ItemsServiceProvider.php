<?php

namespace Modules\Items\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Support\Itqan\ItqanStages;
use Modules\Items\Console\Commands\BackfillLegacyFields;
use Modules\Items\Console\Commands\ItqanCategoryMap;
use Modules\Items\Services\Itqan\ItqanCatalogStage;
use Modules\Items\Services\Itqan\ItqanPricesStage;
use Modules\Items\Console\Commands\AssignColourlessGroups;
use Modules\Items\Console\Commands\LinkMainProducts;
use Modules\Items\Console\Commands\RebuildSearchIndex;
use Modules\Items\Console\Commands\ResetCatalog;
use Modules\Items\Entities\BrandTranslation;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryTranslation;
use Modules\Items\Entities\ManufacturerTranslation;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Entities\ProductTranslation;
use Modules\Items\Services\ItemsPdfService;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Nwidart\Modules\Facades\Module;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ItemsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Items';

    protected string $nameLower = 'items';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        $this->app->singleton(ItemsPdfService::class, function () {
            return new ItemsPdfService();
        });

        $this->registerSearchIndex();

        // مراحل نقل إتقان الخاصة بالاصناف.
        $stages = $this->app->make(ItqanStages::class);
        // الكتالوج الجديد (التصميم 2026-11-21) يأخذ مكان ItqanItemsStage، الذي يبقى ملفاً حتى المرحلة 4.
        $stages->register('items', ItqanCatalogStage::class);
        $stages->register('prices', ItqanPricesStage::class);
    }

    /**
     * تحديث فهرس البحث عند تعديل الصنف او ما يدخل في مستنده (اسمه، تصنيفه،
     * علامته، الشركة المصنّعة). يُكتب في آخر الطلب او المهمة دفعة واحدة.
     */
    protected function registerSearchIndex(): void
    {
        $this->app->scoped(ItemsSearchIndex::class);

        $index = fn (): ItemsSearchIndex => $this->app->make(ItemsSearchIndex::class);

        foreach (['saved', 'deleted', 'restored'] as $event) {
            Product::$event(fn (Product $product) => $index()->markProduct($product->getKey()));
            Category::$event(fn (Category $category) => $index()->markCategory($category->getKey()));
        }

        foreach (['saved', 'deleted'] as $event) {
            ProductTranslation::$event(fn (ProductTranslation $translation) => $index()->markProduct($translation->product_id));
            ProductBarcode::$event(fn (ProductBarcode $barcode) => $index()->markProduct($barcode->product_id));
            CategoryTranslation::$event(fn (CategoryTranslation $translation) => $index()->markCategory($translation->category_id));
            BrandTranslation::$event(fn (BrandTranslation $translation) => $index()->markBrand($translation->brand_id));
            ManufacturerTranslation::$event(fn (ManufacturerTranslation $translation) => $index()->markManufacturer($translation->manufacturer_id));
        }

        $this->app->terminating(fn () => $index()->flush());
        Event::listen(JobProcessed::class, fn () => $index()->flush());
    }

    /**
     * Registration the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(RepositoryServiceProvider::class);
        $this->app->register(SidebarServiceProvider::class);
    }

    /**
     * Registration commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            BackfillLegacyFields::class,
            ItqanCategoryMap::class,
            LinkMainProducts::class,
            RebuildSearchIndex::class,
            ResetCatalog::class,
            AssignColourlessGroups::class,
        ]);
    }

    /**
     * Registration command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Registration translations.
     */
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

    /**
     * Registration config.
     */
    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $this->nameLower.'.'.$config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->merge_config_from($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function merge_config_from(string $path, string $key): void
    {
        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Registration views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\' . $this->name . '\\View\\Components', $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
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
