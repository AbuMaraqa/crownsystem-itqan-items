<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {

            // "constrained()" تنشئ الفهرس تلقائياً
            // لا داعي لكتابة ->index()

            $table->foreignId('unit_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'units')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {

            // 1. احذف المفتاح الخارجي (وفهرسه التلقائي)
            $table->dropForeign(['unit_id']);

            // 2. احذف العمود
            $table->dropColumn('unit_id');
        });
    }
};