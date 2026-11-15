<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix') . 'warehouse_translations';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')
                ->constrained(config('accounting.table_prefix') . 'warehouses')
                ->cascadeOnDelete();
            $table->string('locale')->index('acct_warehouse_trans_locale_idx');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'locale'], 'acct_warehouse_trans_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix') . 'warehouse_translations');
    }
};
