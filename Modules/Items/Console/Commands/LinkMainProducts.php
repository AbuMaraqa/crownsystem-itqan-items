<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Items\Services\MainProductLinker;

class LinkMainProducts extends Command
{
    protected $signature = 'items:link-main-products
        {--dry-run : Report what would be linked without changing anything}
        {--from-categories : Create the main item from the legacy category when the old parent was imported as a category}';

    protected $description = 'Link weight sub-items to their main item using the old system tree (meta_data.legacy_parent_id).';

    public function handle(MainProductLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $linker->link(
            $dryRun,
            (bool) $this->option('from-categories'),
            fn (string $message) => $this->line($message),
        );

        $this->newLine();
        $this->table(['Key', 'Value'], collect($result['stats'])
            ->map(fn ($value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value])
            ->values()
            ->all());

        if ($result['unlinked'] !== []) {
            $path = 'reports/unlinked-sub-items-'.now()->format('Ymd-His').'.csv';
            $rows = array_map(fn (array $row): string => implode(',', array_map(
                fn ($value): string => '"'.str_replace('"', '""', (string) $value).'"',
                $row
            )), $result['unlinked']);

            Storage::disk('local')->put($path, "\u{FEFF}product_id,sku,name,legacy_parent_id,reason\n".implode("\n", $rows));

            $this->warn(count($result['unlinked']).' sub-items were not linked. Report: '.Storage::disk('local')->path($path));

            if ($result['stats']['parent_is_category'] > 0) {
                $this->warn('Their main item was imported as a category. Run again with --from-categories to create it as a product.');
            }
        }

        if ($dryRun) {
            $this->info('Dry run only. No database changes were made.');
        }

        return self::SUCCESS;
    }
}
