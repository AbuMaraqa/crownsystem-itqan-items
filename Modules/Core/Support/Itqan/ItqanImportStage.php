<?php

declare(strict_types=1);

namespace Modules\Core\Support\Itqan;

/**
 * مرحلة من مراحل نقل إتقان (الاصناف، الاسعار، الحسابات...). كل وحدة تسجل مراحلها في ItqanStages.
 * المرحلة قابلة لاعادة التشغيل: تحدّث ما نقلته سابقاً ولا تكرره.
 */
interface ItqanImportStage
{
    /** اسم المرحلة في الامر itqan:import. */
    public function key(): string;

    /** @return array<int, string> مراحل يجب ان تسبقها. */
    public function dependsOn(): array;

    /**
     * @param  callable(string): void  $log
     * @return array<string, int|string|bool> ارقام التقرير
     */
    public function run(ItqanSource $source, bool $dryRun, callable $log): array;
}
