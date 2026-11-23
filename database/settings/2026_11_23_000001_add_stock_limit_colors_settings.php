<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * لونا حدَّي الصنف الادنيين، يختارهما المسؤول من الاعدادات العامة: رصيد عند الحد الادنى 1
 * او تحته يُلوَّن بالاول، وعند الحد الادنى 2 او تحته بالثاني (DashboardTheme::stockColors).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.stock_min_1_color')) {
            $this->migrator->add('general.stock_min_1_color', '#F79009');
        }

        if (! $this->migrator->exists('general.stock_min_2_color')) {
            $this->migrator->add('general.stock_min_2_color', '#D92D20');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.stock_min_1_color');
        $this->migrator->deleteIfExists('general.stock_min_2_color');
    }
};
