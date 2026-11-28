<?php

namespace Modules\Core\Support;

use Modules\Core\Entities\Settings;
use Modules\Core\Settings\GeneralSettings;

/**
 * ترويسة المطبوعات: اسم الشركة وشعارها ووسائل التواصل، من الاعدادات العامة.
 * مصدر واحد لكل ملفات PDF (core::pdf.document)، فتعديل الاعدادات يغيّر كل المطبوعات.
 */
class Letterhead
{
    /**
     * @return array{name: string, logo: ?string, phone: ?string, fax: ?string, email: ?string, website: ?string, corner: string}
     */
    public static function data(): array
    {
        $general = app(GeneralSettings::class);
        $logo = Settings::query()->with('media')->orderBy('id')->first()?->getFirstMedia('logo');

        return [
            'name' => filled($general->company_name) ? $general->company_name : $general->site_name,
            // مسار الملف على القرص لا الرابط: mPDF يقرأه مباشرة بلا طلب HTTP الى الخادم نفسه.
            'logo' => $logo && is_file($logo->getPath()) ? $logo->getPath() : null,
            'phone' => $general->company_phone ?: null,
            'fax' => $general->company_fax ?: null,
            'email' => $general->email_address_for_contact ?: null,
            'website' => $general->company_website ?: null,
            'corner' => module_path('Core', 'resources/assets/pdf/corner.svg'),
        ];
    }
}
