<?php

namespace Modules\Core\Livewire;

use App\View\Components\GuestLayout;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Modules\Core\Enums\UserRole;
use Modules\Core\Filament\Forms\Components\TextInput;
use Modules\Core\Services\ModulePackageService;
use Nwidart\Modules\Facades\Module;

class ModuleSelector extends Component
{

    public $search = '';
    public $selectedFeature = null;
    public $showModal = false;

    protected ModulePackageService $packageService;

    public function boot(ModulePackageService $packageService)
    {
        $this->packageService = $packageService;
    }

    public function mount()
    {
        $this->authorizeModuleSwitch();
    }

    /**
     * اختيار الباقة يعطّل ويفعّل الوحدات للنظام كله، فهو لمدير النظام وحده.
     */
    private function authorizeModuleSwitch(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(UserRole::SUPER_ADMIN->value), 403);
    }

    #[Computed()]
    public function getAvailableFeatures()
    {
        return $this->packageService->getAvailableFeatures();
    }

    public function selectFeature($feature)
    {
        $this->authorizeModuleSwitch();

        // تُفحص الباقة قبل تعطيل اي وحدة، وإلا عطّل اسمٌ غير معروف كل الوحدات.
        $package = $this->packageService->getFeature((string) $feature);

        abort_if(empty($package['modules']), 404);

        $modules = Module::all();
        foreach ($modules as $module) {
            if ($module->isStatus(true)) {
                $module->disable();
//                $this->info("Module '{$module->getName()}' has been disabled.");
            } else {
//                $this->comment("Module '{$module->getName()}' is already disabled.");
            }
        }

        $packageModules = collect($package['modules']);
        $packageModules->each(function ($moduleName) {
            $module = Module::findOrFail($moduleName);
            if ($module) {
                $module->enable();
//                $this->info("Module '{$moduleName}' has been enabled.");
            } else {
//                $this->comment("Module '{$moduleName}' not found.");
            }
        });

        session()->put('selected_module', $feature);
        return $this->redirectRoute('dashboard');
    }

    public function render()
    {
        return view('core::livewire.module-selector')->layout(GuestLayout::class);
    }
}
