<?php

namespace Modules\Items\Console\Commands;

use Illuminate\Console\Command;
use Modules\Items\Support\ColourlessGroup;

/**
 * يضع كل مقطع بالوزن بلا لون في مجموعة «تصنيفه — بلا لون» (ColourlessGroup). بلا --execute يعرض العدد فقط.
 */
class AssignColourlessGroups extends Command
{
    protected $signature = 'items:colourless-groups
        {--execute : Assign for real (without it, only report)}';

    protected $description = 'Put every weight profile without a colour in the «no colour» price group of its category.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $report = ColourlessGroup::assign(dryRun: ! $execute);

        $this->line(($execute ? 'Assigned' : 'Would assign').': '.$report['assigned'].' items, new groups: '.$report['groups_created']);

        if ($report['with_stock'] !== []) {
            $this->warn('Left (they have stock movements): '.count($report['with_stock']).' — ids: '.implode(', ', array_slice($report['with_stock'], 0, 50)));
        }

        if (! $execute) {
            $this->warn('Report only. Run with --execute to assign.');
        }

        return self::SUCCESS;
    }
}
