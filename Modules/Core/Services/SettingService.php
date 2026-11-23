<?php

namespace Modules\Core\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Repositories\Contracts\SettingRepositoryInterface;
use Modules\Core\Settings\GeneralSettings;

class SettingService
{
    public function __construct(
        private readonly SettingRepositoryInterface $settings,
        private readonly GeneralSettings $general,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?UploadedFile $logo, ?int $userId): void
    {
        DB::transaction(function () use ($data, $logo, $userId): void {
            $holder = $this->settings->logoHolder();

            if ($logo) {
                $holder->addMedia($logo)->toMediaCollection('logo', 'media');
            } elseif ($data['remove_logo'] ?? false) {
                $holder->clearMediaCollection('logo');
            }

            $this->general->site_name = $data['site_name'];
            $this->general->email_address_for_contact = $data['email_address_for_contact'];
            $this->general->site_description = $data['site_description'];
            $this->general->base_currency_id = $data['base_currency_id'];
            $this->general->weight_variance_tolerance = (float) $data['weight_variance_tolerance'];
            $this->general->dashboard_color = strtoupper($data['dashboard_color'] ?? $this->general->dashboard_color);
            $this->general->dashboard_font = $data['dashboard_font'] ?? $this->general->dashboard_font;
            $this->general->stock_min_1_color = strtoupper($data['stock_min_1_color'] ?? $this->general->stock_min_1_color);
            $this->general->stock_min_2_color = strtoupper($data['stock_min_2_color'] ?? $this->general->stock_min_2_color);
            $this->general->site_logo_url = $this->settings->logoHolder()->getFirstMediaUrl('logo') ?: null;
            $this->general->save();

            if (array_key_exists('app_versions', $data)) {
                foreach ($data['app_versions'] as $version) {
                    $this->settings->saveAppVersion($version);
                }

                $this->settings->deleteAppVersionsExcept(array_column($data['app_versions'], 'platform'));
            }

            if (array_key_exists('additional_cost_types', $data)) {
                $this->settings->syncAdditionalCostTypes($data['additional_cost_types'], $userId);
            }
        });

        // api/v1/config يخزّن اصدارات التطبيق بلا انتهاء، فيُمسح التخزين عند تعديلها.
        Cache::forget('app_bootstrap_config');
    }
}
