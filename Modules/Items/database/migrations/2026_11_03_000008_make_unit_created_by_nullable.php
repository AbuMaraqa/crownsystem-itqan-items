<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الوحدة التي ينشئها الاستيراد لا منشئ لها، كالاصناف المستوردة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('items.table_prefix').'units', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // لا رجوع الى NOT NULL: وحدات مستوردة قد تكون بلا منشئ.
    }
};
