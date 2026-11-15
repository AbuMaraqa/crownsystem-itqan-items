<?php

namespace Modules\Items\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Items\Services\LegacyItemsImporter;

class LegacyItemsSeeder extends Seeder
{
    public function run(LegacyItemsImporter $importer): void
    {
        $file = $this->resolveSqlFile();
        if ($file === null) {
            $this->command->warn('Legacy items SQL file was not found. Skipping legacy products seed.');

            return;
        }

        $dryRun = filter_var(env('ITEMS_SEED_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN);
        $allNodesAsProducts = filter_var(env('ITEMS_SEED_ALL_NODES_AS_PRODUCTS', false), FILTER_VALIDATE_BOOLEAN);

        $stats = $importer->import(
            $file,
            $dryRun,
            $allNodesAsProducts,
            function (string $message): void {
                $this->command->info($message);
            }
        );

        foreach ($stats as $key => $value) {
            $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $this->command->info("{$key}={$displayValue}");
        }
    }

    public static function hasSeedFile(): bool
    {
        foreach (self::candidateSqlFiles() as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSqlFile(): ?string
    {
        foreach (self::candidateSqlFiles() as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function candidateSqlFiles(): array
    {
        return array_values(array_unique(array_filter([
            env('ITEMS_SEED_SQL'),
            base_path('database/seeders/data/items.sql'),
            storage_path('app/seed/items.sql'),
            'C:\\Users\\Mohamad Maraqa\\Downloads\\items (1).sql',
        ], fn ($path): bool => is_string($path) && $path !== '')));
    }
}
