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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->index()->nullable()->constrained(config('branch.table_prefix') . 'branches')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            // قواعد قديمة سمّت الفهرس 'branch_id'، والجديدة تأخذ الاسم الافتراضي (users_branch_id_index).
            $table->dropIndex(Schema::hasIndex('users', 'branch_id') ? 'branch_id' : ['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
