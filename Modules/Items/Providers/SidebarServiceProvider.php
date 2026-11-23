<?php

namespace Modules\Items\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\SidebarGroup;
use Modules\Core\Services\SidebarItem;
use Modules\Core\Services\SidebarService;

class SidebarServiceProvider extends ServiceProvider
{
    /**
     * Registration the service provider.
     */
    public function register(): void {
        if (! $this->app->bound(SidebarService::class)) {
            $this->app->singleton(SidebarService::class, function () {
                return new SidebarService();
            });
        }
    }

    public function boot(): void {
        $sidebar = $this->app->make(SidebarService::class);

        $sidebar->add(
            (new SidebarItem('Orders List' , 'solar-box-bold-duotone', ['Order View List'] , 'orders.index', 20))
        );

        $sidebar->add(
            (new SidebarGroup('Products', 'solar-box-bold-duotone', ['Product View List'], 15))
                ->add(new SidebarItem('Products List' , 'solar-box-bold-duotone', ['Product View List'] , 'products.index'))
                ->add(new SidebarItem('Items Tree' , 'solar-box-bold-duotone', ['Product View List'] , 'products.tree'))
                ->add(new SidebarItem('Add Product' , 'solar-box-bold-duotone', ['Product Create'] , 'products.add'))
                ->add(new SidebarItem('Categories List' , 'solar-box-bold-duotone', ['Category View List'] , 'categories.index'))
                ->add(new SidebarItem('Categories Tree' , 'solar-box-bold-duotone', ['Category View List'] , 'categories.tree'))
                ->add(new SidebarItem('Colours List' , 'solar-box-bold-duotone', ['Finish View List'] , 'finishes.index'))
                ->add(new SidebarItem('Attributes List' , 'solar-box-bold-duotone', ['Attribute View List'] , 'attributes.index'))
                ->add(new SidebarItem('Tags List' , 'solar-box-bold-duotone', ['Tag View List'] , 'tags.index'))
                ->add(new SidebarItem('Labels List' , 'solar-box-bold-duotone', ['Label View List'] , 'labels.index'))
                ->add(new SidebarItem('Brands List' , 'solar-box-bold-duotone', ['Brand View List'] , 'brands.index'))
                ->add(new SidebarItem('Manufacturers List' , 'solar-box-bold-duotone', ['Manufacturer View List'] , 'manufacturers.index'))
                ->add(new SidebarItem('Units List' , 'solar-box-bold-duotone', ['Unit View List'] , 'units.index'))
                ->add(new SidebarItem('Item Types List' , 'solar-box-bold-duotone', ['Item Type Profile View List'] , 'item-type-profiles.index'))
                ->add(new SidebarItem('Price Lists' , 'solar-box-bold-duotone', ['Price List View List'] , 'price-lists.index'))
                ->add(new SidebarItem('Offers List' , 'solar-box-bold-duotone', ['Offer View List'] , 'offers.index'))
                ->add(new SidebarItem('Addons List' , 'solar-box-bold-duotone', ['Addon View List'] , 'addons.index'))
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
