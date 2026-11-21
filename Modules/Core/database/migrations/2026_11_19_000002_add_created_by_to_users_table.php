<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * من أضاف المستخدم (عمود «أضيف بواسطة» في جدول المستخدمين). فارغ لمن سجّل بنفسه أو أُضيف قبل هذا العمود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'users_created_by_fk')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign('users_created_by_fk');
                $table->dropColumn('created_by');
            });
        }
    }
};
