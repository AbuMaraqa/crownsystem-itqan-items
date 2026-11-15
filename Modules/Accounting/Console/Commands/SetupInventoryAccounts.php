<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Support\InventoryPermissions;

/**
 * يُنشئ حسابات المخزون الناقصة (بضاعة مستلمة لم تصل فواتيرها، فروقات الاسعار، الارصدة
 * الافتتاحية) وحساباً فرعياً لكل مخزن تحت «المخزون»، ويزامن الصلاحيات.
 * الترحيل ينشئها تلقائياً عند الحاجة؛ هذا الامر لتجهيزها مسبقاً.
 */
class SetupInventoryAccounts extends Command
{
    protected $signature = 'inventory:setup';

    protected $description = 'Create inventory ledger accounts, per-warehouse accounts and permissions';

    public function handle(InventoryAccounts $accounts): int
    {
        InventoryPermissions::sync();
        $accounts->ensureDefaults();

        $this->info('Inventory accounts and permissions are ready.');

        return self::SUCCESS;
    }
}
