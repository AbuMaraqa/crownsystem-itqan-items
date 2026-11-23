export interface AppVersionForm {
    platform: string;
    store_url: string;
    min_version: string;
    latest_version: string;
    maintenance_mode: boolean;
    message: string | null;
}

/** An additional cost item (Accounting); a new row has no id yet. */
export interface CostTypeForm {
    id: number | null;
    name: string;
    is_active: boolean;
}

export interface FontOption {
    value: string;
    label: string;
    stack: string;
}

export interface SettingsForm {
    _method: 'put';
    site_name: string;
    email_address_for_contact: string;
    site_description: string;
    base_currency_id: number | null;
    weight_variance_tolerance: number | string;
    dashboard_color: string;
    dashboard_font: string;
    stock_min_1_color: string;
    stock_min_2_color: string;
    logo: File | null;
    remove_logo: boolean;
    app_versions: AppVersionForm[];
    additional_cost_types: CostTypeForm[];
}
