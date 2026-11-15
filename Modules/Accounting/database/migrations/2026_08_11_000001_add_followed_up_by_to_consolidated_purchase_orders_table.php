<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_orders';

        if (Schema::hasColumn($tableName, 'followed_up_by')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $table->unsignedBigInteger('followed_up_by')->nullable()->after('created_by');
            $table->foreign('followed_up_by', 'acct_cpo_followed_up_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_orders';

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropForeign('acct_cpo_followed_up_by_fk');
            $table->dropColumn('followed_up_by');
        });
    }
};
