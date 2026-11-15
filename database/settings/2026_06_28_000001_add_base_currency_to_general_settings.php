<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.base_currency_id', null);
    }

    public function down(): void
    {
        $this->migrator->delete('general.base_currency_id');
    }
};
