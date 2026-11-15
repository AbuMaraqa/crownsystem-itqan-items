<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\StockAlertService;

/**
 * يعيد حساب حالة كل صنف مراقَب في قسم متابعة المخزون (بعد استيراد بيانات او تعديل مباشر في القاعدة).
 * بلا اشعارات افتراضياً؛ --notify يرسل اشعاراً لكل صنف تسوء حالته.
 */
class RebuildStockAlerts extends Command
{
    protected $signature = 'inventory:stock-alerts {--notify : Send notifications for items whose status gets worse}';

    protected $description = 'Recalculate the stock follow-up status of every monitored item';

    public function handle(StockAlertService $alerts): int
    {
        $count = $alerts->rebuild((bool) $this->option('notify'));

        $this->info("Evaluated {$count} monitored items.");

        return self::SUCCESS;
    }
}
