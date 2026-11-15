<?php

namespace Modules\Items\database\seeders;

use Illuminate\Database\Seeder;

class ItemsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
        ]);

        if (LegacyItemsSeeder::hasSeedFile()) {
            $this->call([
                // LegacyItemsSeeder::class,
            ]);
        } else {
            $this->command->warn('Legacy items SQL file not found. Skipping legacy products seed.');
        }
    }
}
