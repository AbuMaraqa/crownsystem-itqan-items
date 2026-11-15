<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * لون لوحة التحكم وخطها، يختارهما المسؤول من الاعدادات العامة (DashboardTheme).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.dashboard_color', '#1570EF');
        $this->migrator->add('general.dashboard_font', 'tajawal');
    }

    public function down(): void
    {
        $this->migrator->delete('general.dashboard_color');
        $this->migrator->delete('general.dashboard_font');
    }
};
