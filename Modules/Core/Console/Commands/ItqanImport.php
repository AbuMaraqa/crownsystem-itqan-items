<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Entities\User;
use Modules\Core\Support\Itqan\ItqanSource;
use Modules\Core\Support\Itqan\ItqanStages;
use Throwable;

/**
 * نقل بيانات برنامج إتقان من ملفات قاعدته مباشرة. كل مرحلة قابلة لاعادة التشغيل دون تكرار.
 * مثال: php artisan itqan:import items prices --path="C:\Itqan\almamoun2015"
 */
class ItqanImport extends Command
{
    protected $signature = 'itqan:import
        {stages?* : Stages to run (default: all registered stages)}
        {--path= : Itqan data folder (almamoun2015). Defaults to ITQAN_DATA_PATH}
        {--dry-run : Read and report without writing}
        {--as-user= : Id of the admin user the imported records are attributed to}';

    protected $description = 'Import data from the old Itqan system (MyISAM files) into CrownSystem.';

    public function handle(ItqanStages $stages): int
    {
        try {
            $source = ItqanSource::fromConfig($this->option('path'));
            $ordered = $stages->ordered((array) $this->argument('stages'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($userId = $this->option('as-user')) {
            $user = User::query()->find($userId);

            if (! $user) {
                $this->error("User [{$userId}] not found.");

                return self::FAILURE;
            }

            Auth::setUser($user);
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info('Itqan source: '.$source->path().($dryRun ? ' (dry run)' : ''));

        foreach ($ordered as $stage) {
            $this->newLine();
            $this->info("== {$stage->key()} ==");
            $started = microtime(true);

            try {
                $report = $stage->run($source, $dryRun, fn (string $message) => $this->line($message));
            } catch (Throwable $exception) {
                $this->error("Stage [{$stage->key()}] failed: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $report['seconds'] = round(microtime(true) - $started, 1);

            $this->table(['Key', 'Value'], collect($report)
                ->map(fn ($value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value])
                ->values()
                ->all());
        }

        return self::SUCCESS;
    }
}
