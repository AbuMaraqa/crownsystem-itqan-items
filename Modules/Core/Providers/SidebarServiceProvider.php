<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\SidebarGroup;
use Modules\Core\Services\SidebarItem;
use Modules\Core\Services\SidebarService;

class SidebarServiceProvider extends ServiceProvider
{
    /**
     * Registration the service provider.
     */
    public function register(): void
    {
        if (! $this->app->bound(SidebarService::class)) {
            $this->app->singleton(SidebarService::class, function () {
                return new SidebarService;
            });
        }
    }

    public function boot(): void
    {
        $sidebar = $this->app->make(SidebarService::class);

        $sidebar->add(
            (new SidebarItem('Home', 'solar-home-2-bold-duotone', [], 'home', 1))
        );

        $sidebar->add(
            (new SidebarGroup('Users', 'solar-users-group-rounded-bold-duotone', [], 30))
                ->add(new SidebarItem('Users List', 'solar-users-group-rounded-bold-duotone', ['User View List'], 'users.index'))
                ->add(new SidebarItem('Add User', 'solar-users-group-rounded-bold-duotone', ['User Create'], 'users.add'))
        );

        $sidebar->add(
            (new SidebarGroup('Settings', 'solar-settings-bold-duotone', [], 140))
                ->add(new SidebarItem('System Settings', 'solar-settings-bold-duotone', ['Setting View List'], 'settings'))
                ->add(new SidebarItem('Roles And Permissions', 'solar-lock-bold-duotone', ['Roles And Permissions View List'], 'roles.index'))
                ->add(new SidebarItem('Currencies', 'solar-wallet-money-bold-duotone', ['Currency View List'], 'currencies.index'))
                ->add(new SidebarItem('Countries', 'solar-global-bold-duotone', ['Country View List'], 'countries.index'))
                ->add(new SidebarItem('Governorates', 'solar-box-bold-duotone', ['Governorate View List'], 'governorates.index'))
                ->add(new SidebarItem('Cities', 'solar-box-bold-duotone', ['City View List'], 'cities.index'))
                ->add(new SidebarItem('Districts', 'solar-box-bold-duotone', ['District View List'], 'districts.index'))
                ->add(new SidebarItem('Additional Cost Items', 'solar-banknote-2-bold-duotone', ['Additional Cost Type View List'], 'accounting.additional-cost-types.index'))
                ->add(new SidebarItem('Reappraisal Elements', 'solar-scale-bold-duotone', ['Reappraisal Element View List'], 'accounting.reappraisal-elements.index'))
                ->add(new SidebarItem('Attachment Categories', 'solar-paperclip-bold-duotone', ['Attachment Category View List'], 'accounting.attachment-categories.index'))
                ->add(new SidebarItem('Clearance Attachment Types', 'solar-clipboard-list-bold-duotone', ['Clearance Attachment Type View List'], 'accounting.clearance-attachment-types.index'))
                ->add(new SidebarItem('Delivery Cost Elements', 'solar-clipboard-list-bold-duotone', ['Delivery Cost Element View List'], 'accounting.delivery-cost-elements.index'))
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
