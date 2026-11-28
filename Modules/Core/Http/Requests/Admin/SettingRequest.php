<?php

namespace Modules\Core\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Core\Entities\Currency;
use Modules\Core\Support\DashboardTheme;

class SettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // وسيط can على المسار يقرر
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'email_address_for_contact' => ['required', 'email', 'max:255'],
            'site_description' => ['required', 'string'],
            'base_currency_id' => ['required', 'integer', Rule::exists((new Currency)->getTable(), 'id')],
            'weight_variance_tolerance' => ['required', 'numeric', 'min:0', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'remove_logo' => ['boolean'],

            'dashboard_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) && ! DashboardTheme::isReadable($value)) {
                    $fail(__('This color is too light: white text on buttons would be hard to read.'));
                }
            }],
            'dashboard_font' => ['sometimes', 'required', Rule::in(array_keys(DashboardTheme::fonts()))],
            'stock_min_1_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'stock_min_2_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            // ترويسة المطبوعات (Letterhead)
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_fax' => ['nullable', 'string', 'max:50'],
            'company_website' => ['nullable', 'string', 'max:255'],

            'app_versions' => ['sometimes', 'array'],
            'app_versions.*.platform' => ['required', 'string', 'max:255', 'distinct'],
            'app_versions.*.store_url' => ['required', 'url:http,https', 'max:255'],
            'app_versions.*.min_version' => ['required', 'string', 'max:255'],
            'app_versions.*.latest_version' => ['required', 'string', 'max:255'],
            'app_versions.*.maintenance_mode' => ['boolean'],
            'app_versions.*.message' => ['nullable', 'string'],

            'additional_cost_types' => ['sometimes', 'array'],
            'additional_cost_types.*.id' => ['nullable', 'integer', Rule::exists((new AdditionalCostType)->getTable(), 'id')],
            'additional_cost_types.*.name' => ['required', 'string', 'max:255'],
            'additional_cost_types.*.is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'site_name' => __('Site Name'),
            'email_address_for_contact' => __('Email Address For Contact'),
            'site_description' => __('Site Description'),
            'base_currency_id' => __('Base Currency'),
            'weight_variance_tolerance' => __('Weight Variance Tolerance'),
            'logo' => __('Site Logo'),
            'dashboard_color' => __('Dashboard color'),
            'dashboard_font' => __('Dashboard font'),
            'stock_min_1_color' => __('Min Limit 1 color'),
            'stock_min_2_color' => __('Min Limit 2 color'),
            'company_name' => __('Company Name'),
            'company_phone' => __('Phone'),
            'company_fax' => __('Fax'),
            'company_website' => __('Website'),
            'app_versions.*.platform' => __('Platform (e.g., android, ios)'),
            'app_versions.*.store_url' => __('Store URL'),
            'app_versions.*.min_version' => __('Min Version'),
            'app_versions.*.latest_version' => __('Latest Version'),
            'app_versions.*.message' => __('Maintenance / Update Message'),
            'additional_cost_types.*.name' => __('Cost Item'),
        ];
    }
}
