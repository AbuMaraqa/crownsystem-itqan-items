<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Support\InventoryPermissions;

/**
 * صلاحية السماح بالرصيد السالب، كصلاحية NegativeAmount في اتقان.
 */
return new class extends Migration
{
    public function up(): void
    {
        InventoryPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
