<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

use Illuminate\Support\Facades\Auth;
use Modules\Core\Entities\User;
use RuntimeException;

/**
 * تشغيل مراحل نقل إتقان بالترتيب. يستعمله الامر itqan:import والـ ItqanImportSeeder، فالمنطق واحد.
 */
final class ItqanImportRunner
{
    public function __construct(private readonly ItqanStages $stages) {}

    /**
     * @param  array<int, string>  $stages  فارغة = كل المراحل المسجلة
     * @param  callable(string): void  $log
     * @param  callable(string, array<string, mixed>): void  $report  اسم المرحلة وتقريرها
     */
    public function run(ItqanSource $source, array $stages, bool $dryRun, ?int $userId, callable $log, callable $report): void
    {
        $ordered = $this->stages->ordered($stages);

        if ($userId !== null) {
            $user = User::query()->find($userId) ?? throw new RuntimeException("User [{$userId}] not found.");
            Auth::setUser($user);
        }

        $log('Itqan source: '.$source->path().($dryRun ? ' (dry run)' : ''));

        foreach ($ordered as $stage) {
            $log("== {$stage->key()} ==");
            $started = microtime(true);

            try {
                $result = $stage->run($source, $dryRun, $log);
            } catch (\Throwable $exception) {
                throw new RuntimeException("Stage [{$stage->key()}] failed: {$exception->getMessage()}", 0, $exception);
            }

            $result['seconds'] = round(microtime(true) - $started, 1);
            $report($stage->key(), $result);
        }
    }
}
