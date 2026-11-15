<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاحا الوحدة والعلامة التجارية كانا ON DELETE CASCADE، فحذف وحدة (مثل "كغم") يحذف من
 * قاعدة البيانات كل صنف يستخدمها مع اصنافه الفرعية وارتباطاته. يصيران ON DELETE SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildForeignKeys('nullOnDelete');
    }

    public function down(): void
    {
        $this->rebuildForeignKeys('cascadeOnDelete');
    }

    private function rebuildForeignKeys(string $onDelete): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix, $onDelete) {
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['brand_id']);

            $table->foreign('unit_id')->references('id')->on($prefix.'units')->{$onDelete}();
            $table->foreign('brand_id')->references('id')->on($prefix.'brands')->{$onDelete}();
        });
    }
};
