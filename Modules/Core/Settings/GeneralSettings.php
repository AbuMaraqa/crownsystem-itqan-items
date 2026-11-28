<?php

namespace Modules\Core\Settings;

use Spatie\LaravelSettings\Settings;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class GeneralSettings extends Settings
{
    public string $site_name = 'Be Found Online';
    public string $email_address_for_contact = 'contact@bfo.com';
    public string $site_description = 'Be Found Online Description';
    public ?string $site_logo_url = null;
    public ?int $base_currency_id = null;
    // نسبة فرق الوزن المسموحة في سطر الوزن، وفوقها يظهر تنبيه لا يمنع الحفظ.
    public float $weight_variance_tolerance = 5;
    // لون لوحة التحكم وخطها (DashboardTheme).
    public string $dashboard_color = '#1570EF';
    public string $dashboard_font = 'tajawal';
    // لون الرصيد عند الحد الادنى 1 او تحته، ولونه عند الحد الادنى 2 او تحته (DashboardTheme::stockColors).
    public string $stock_min_1_color = '#F79009';
    public string $stock_min_2_color = '#D92D20';
    // ترويسة المطبوعات (Letterhead): الاسم الرسمي للشركة ووسائل التواصل؛ الاسم الفارغ يعني اسم الموقع.
    public ?string $company_name = null;
    public ?string $company_phone = null;
    public ?string $company_fax = null;
    public ?string $company_website = null;

    public static function group(): string
    {
        return 'general';
    }
}
