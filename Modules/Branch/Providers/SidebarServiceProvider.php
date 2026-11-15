<?php

namespace Modules\Branch\Providers;

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
                return new SidebarService();
            });
        }
    }

    public function boot(): void
    {
        $sidebar = $this->app->make(SidebarService::class);

        $sidebar->add(
            (new SidebarGroup('Branches', 'solar-buildings-bold-duotone', ['Branch View List'], 120))
                ->add(new SidebarItem('Branches List', 'solar-virus-bold-duotone', ['Branch View List'], 'branches.index'))
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
