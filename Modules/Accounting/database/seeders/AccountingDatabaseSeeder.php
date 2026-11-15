<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;

class AccountingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            FiscalYearSeeder::class,
            DefaultChartOfAccountsSeeder::class,
            AccountMappingSeeder::class,
        ]);
    }
}
