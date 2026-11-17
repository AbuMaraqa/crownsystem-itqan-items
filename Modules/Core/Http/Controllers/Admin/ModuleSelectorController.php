<?php

namespace Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Enums\UserRole;
use Modules\Core\Services\ModulePackageService;

/**
 * اختيار باقة الوحدات: يعطّل ويفعّل الوحدات للنظام كله، فهو لمدير النظام وحده.
 */
class ModuleSelectorController extends Controller
{
    public function __construct(private readonly ModulePackageService $packages) {}

    public function index(Request $request): Response
    {
        $this->authorizeSwitch($request);

        return Inertia::render('Core/ModuleSelector/Index', [
            'packages' => $this->packages->getAvailableFeatures()
                ->map(fn (array $package, string $key) => [
                    'key' => $key,
                    'name' => __($package['display_name'] ?? $key),
                    'description' => __($package['description'] ?? ''),
                    'modules' => array_values($package['modules'] ?? []),
                    'features' => array_map(fn (string $feature) => __($feature), $package['features'] ?? []),
                ])
                ->values(),
            'current' => $request->session()->get('selected_module'),
        ]);
    }

    public function select(Request $request): RedirectResponse
    {
        $this->authorizeSwitch($request);

        $data = $request->validate([
            'package' => ['required', 'string', Rule::in($this->packages->getAvailableFeatures()->keys()->all())],
        ]);

        abort_unless($this->packages->activate($data['package']), 404);

        return redirect()->route('dashboard')->with('success', __('Package activated'));
    }

    private function authorizeSwitch(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasRole(UserRole::SUPER_ADMIN->value), 403);
    }
}
