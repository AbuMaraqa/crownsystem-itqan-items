<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * نسبة فرق الوزن المسموحة بين الوزن الفعلي والمحسوب في سطر الوزن، وفوقها يظهر تنبيه لا يمنع الحفظ.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.weight_variance_tolerance', 5.0);
    }

    public function down(): void
    {
        $this->migrator->delete('general.weight_variance_tolerance');
    }
};
