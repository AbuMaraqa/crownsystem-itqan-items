<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Modules\Items\Services\LegacyFieldsBackfill;

class BackfillLegacyFields extends Command
{
    protected $signature = 'items:backfill-legacy-fields
        {--apply : Write the changes. Without it the command only reports what would change}';

    protected $description = 'Repair imported items from meta_data: weight per meter, length, shelf, bundle count, wrongly mapped weight and multi-barcode strings. Only fills empty values.';

    public function handle(LegacyFieldsBackfill $backfill): int
    {
        $apply = (bool) $this->option('apply');

        $stats = $backfill->run($apply, fn (string $message) => $this->line($message));

        $this->newLine();
        $this->table(['Key', 'Value'], collect($stats)
            ->map(fn ($value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value])
            ->values()
            ->all());

        if (! $apply) {
            $this->info('Dry run only. No database changes were made. Run again with --apply to write them.');
        }

        return self::SUCCESS;
    }
}
