<?php

declare(strict_types=1);

namespace Modules\Items\Services\Itqan;

use Modules\Core\Support\Itqan\ItqanImportStage;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Services\MainProductLinker;

/**
 * مرحلة الاصناف: شجرة إتقان وأصنافه وباركوداته ووحداته من جدول items، بمنطق LegacyItemsImporter
 * المجرَّب. ثم ربط المقاطع بأصنافها الرئيسية، وانشاء الرئيسي من التصنيف للمقاطع التي أبوها تصنيف.
 */
final class ItqanItemsStage implements ItqanImportStage
{
    public function __construct(
        private readonly LegacyItemsImporter $importer,
        private readonly MainProductLinker $linker,
    ) {}

    public function key(): string
    {
        return 'items';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function run(ItqanSource $source, bool $dryRun, callable $log): array
    {
        $log('Reading Itqan items ('.$source->count('items').' rows)...');

        $report = $this->importer->importRows(
            $source->rows('items', LegacyItemsImporter::LEGACY_COLUMNS),
            $source->path().DIRECTORY_SEPARATOR.'items',
            $dryRun,
            false,
            $log,
        );

        $log('Linking sub-items to their main items...');
        $linked = $this->linker->link($dryRun, true, $log);

        foreach ($linked['stats'] as $key => $value) {
            $report['linker_'.$key] = $value;
        }

        $report['linker_unlinked'] = count($linked['unlinked']);
        unset($report['source_file']);

        return $report;
    }
}
