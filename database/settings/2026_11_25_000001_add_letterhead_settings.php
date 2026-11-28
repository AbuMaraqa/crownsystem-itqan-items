<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * ترويسة المطبوعات (Modules\Core\Support\Letterhead): الاسم الرسمي للشركة والهاتف والفاكس والموقع،
 * تظهر في رأس كل ملف PDF. البريد هو بريد التواصل الموجود.
 */
return new class extends SettingsMigration
{
    private const KEYS = ['company_name', 'company_phone', 'company_fax', 'company_website'];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            if (! $this->migrator->exists("general.{$key}")) {
                $this->migrator->add("general.{$key}", null);
            }
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $key) {
            $this->migrator->deleteIfExists("general.{$key}");
        }
    }
};
