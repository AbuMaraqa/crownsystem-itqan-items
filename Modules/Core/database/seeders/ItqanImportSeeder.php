<?php

namespace Modules\Core\database\seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Console\Commands\ItqanImport;
use Modules\Core\Entities\User;
use Modules\Core\Support\Itqan\ItqanImportRunner;
use Modules\Core\Support\Itqan\ItqanSource;

/**
 * نقل بيانات برنامج إتقان (الاصناف وقوائم الاسعار حالياً) من ملفات قاعدة almamoun2015.
 * لا يُستدعى من DatabaseSeeder لأنه ثقيل؛ يُشغَّل وحده:
 *
 *   php -d memory_limit=3G artisan db:seed --class="Modules\Core\database\seeders\ItqanImportSeeder" --force
 *
 * الاعدادات من .env (تُقرأ عبر config('core.itqan')؛ بعد تغييرها مع config:cache شغّل config:clear):
 *   ITQAN_DATA_PATH   مجلد almamoun2015 (الزامي)
 *   ITQAN_STAGES      مراحل مفصولة بفاصلة، مثل items,prices (فارغ = كل المراحل)
 *   ITQAN_DRY_RUN     true للتجربة دون كتابة
 *   ITQAN_USER_ID     المستخدم الذي تُنسب اليه السجلات (فارغ = اول Super Admin)
 *
 * اعادة التشغيل آمنة: تحدّث ما نُقل ولا تكرره، فإن انقطع يُعاد الامر نفسه.
 */
class ItqanImportSeeder extends Seeder
{
    public function run(ItqanImportRunner $runner): void
    {
        // من config لا env: على السيرفر مع config:cache يعيد env() قيمة فارغة.
        $stages = array_values(array_filter(array_map('trim', explode(',', (string) config('core.itqan.stages')))));

        $runner->run(
            ItqanSource::fromConfig(),
            $stages,
            filter_var(config('core.itqan.dry_run'), FILTER_VALIDATE_BOOLEAN),
            $this->userId(),
            fn (string $message) => $this->command?->line($message),
            fn (string $stage, array $report) => $this->command?->table(['Key', 'Value'], ItqanImport::rows($report)),
        );
    }

    private function userId(): ?int
    {
        if ($id = config('core.itqan.user_id')) {
            return (int) $id;
        }

        $admin = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin'))->orderBy('id')->first();

        return $admin?->getKey();
    }
}
