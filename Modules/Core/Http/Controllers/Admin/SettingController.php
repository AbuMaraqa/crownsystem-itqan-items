<?php

namespace Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Core\Entities\AppVersion;
use Modules\Core\Entities\Currency;
use Modules\Core\Http\Requests\Admin\SettingRequest;
use Modules\Core\Repositories\Contracts\SettingRepositoryInterface;
use Modules\Core\Services\SettingService;
use Modules\Core\Settings\GeneralSettings;
use Modules\Core\Support\DashboardTheme;

class SettingController extends Controller
{
    public function __construct(
        private readonly SettingRepositoryInterface $settings,
        private readonly SettingService $settingService,
    ) {}

    public function index(GeneralSettings $general, DashboardTheme $theme): Response
    {
        $logo = $this->settings->logoHolder()->getFirstMedia('logo');

        return Inertia::render('Core/Settings/Index', [
            'settings' => [
                'site_name' => $general->site_name,
                'email_address_for_contact' => $general->email_address_for_contact,
                'site_description' => $general->site_description,
                'base_currency_id' => $general->base_currency_id ?: $this->settings->fallbackCurrencyId(),
                'weight_variance_tolerance' => $general->weight_variance_tolerance,
                'dashboard_color' => $general->dashboard_color,
                'dashboard_font' => $general->dashboard_font,
                'stock_min_1_color' => $theme->stockColors()['min_1'],
                'stock_min_2_color' => $theme->stockColors()['min_2'],
            ],
            'currencyOptions' => $this->settings->currencies()
                ->map(fn (Currency $currency) => ['value' => $currency->id, 'label' => $currency->code])
                ->values(),
            'colorPresets' => config('core.dashboard_color_presets'),
            'fonts' => DashboardTheme::fontOptions(),
            // كل الخطوط، فيظهر كل خيار بحروفه.
            'fontPreviewUrl' => DashboardTheme::previewUrl(),
            'logo' => $logo ? [['id' => $logo->id, 'url' => $logo->getUrl()]] : [],
            'appVersions' => $this->settings->appVersions()->map(fn (AppVersion $version) => [
                'platform' => $version->platform,
                'store_url' => $version->store_url,
                'min_version' => $version->min_version,
                'latest_version' => $version->latest_version,
                'maintenance_mode' => (bool) $version->maintenance_mode,
                'message' => $version->message,
            ])->values(),
            'additionalCostTypes' => $this->settings->additionalCostTypes()->map(fn (AdditionalCostType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'is_active' => (bool) $type->is_active,
            ])->values(),
        ]);
    }

    public function update(SettingRequest $request): RedirectResponse
    {
        $this->settingService->save($request->safe()->except('logo'), $request->file('logo'), $request->user()->id);

        return to_route('settings')->with('success', __('Saved Successfully'));
    }
}
