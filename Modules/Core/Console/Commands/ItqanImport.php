<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Support\Itqan\ItqanImportRunner;
use Modules\Core\Support\Itqan\ItqanSource;
use Throwable;

/**
 * نقل بيانات برنامج إتقان من ملفات قاعدته مباشرة. كل مرحلة قابلة لاعادة التشغيل دون تكرار.
 * مثال: php artisan itqan:import items prices --path="C:\Itqan\almamoun2015"
 * وله seeder مقابل: ItqanImportSeeder.
 */
class ItqanImport extends Command
{
    protected $signature = 'itqan:import
        {stages?* : Stages to run (default: all registered stages)}
        {--path= : Itqan data folder (almamoun2015). Defaults to ITQAN_DATA_PATH}
        {--dry-run : Read and report without writing}
        {--as-user= : Id of the admin user the imported records are attributed to}
        {--sample : Items stage: import only the small test sample (Alubin in 4 colours, 20 accessories, 1 rubber)}
        {--all-finishes : Items stage: create a colour child for every legacy colour, not only the used ones}';

    protected $description = 'Import data from the old Itqan system (MyISAM files) into CrownSystem.';

    public function handle(ItqanImportRunner $runner): int
    {
        // واجهة المرحلة ثابتة (run(source, dryRun, log))، فخيارات مرحلة الاصناف تُمرَّر بالإعداد.
        config([
            'core.itqan.sample' => (bool) $this->option('sample'),
            'core.itqan.all_finishes' => (bool) $this->option('all-finishes'),
        ]);

        try {
            $runner->run(
                ItqanSource::fromConfig($this->option('path')),
                (array) $this->argument('stages'),
                (bool) $this->option('dry-run'),
                $this->option('as-user') !== null ? (int) $this->option('as-user') : null,
                fn (string $message) => $this->line($message),
                fn (string $stage, array $report) => $this->table(['Key', 'Value'], self::rows($report)),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array{0: string, 1: string}>
     */
    public static function rows(array $report): array
    {
        return collect($report)
            ->map(fn ($value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value])
            ->values()
            ->all();
    }
}
