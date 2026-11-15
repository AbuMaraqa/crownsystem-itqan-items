<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * متابعة المخزون:
 * - stock_alerts: صف لكل صنف داخل قسم المتابعة (رصيده عند الحد الادنى 1 او اقل)، بحالته الحالية.
 *   يُحذف الصف عند خروج الصنف من المتابعة، فالمقارنة بالحالة السابقة تتم معه، والقسم يقرأ منه مباشرة.
 * - stock_limit_changes: سجل كل تعديل على حدود الصنف (من عدّل، ومتى، والقيم القديمة والجديدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $products = config('items.table_prefix').'products';

        if (! Schema::hasTable($prefix.'stock_alerts')) {
            Schema::create($prefix.'stock_alerts', function (Blueprint $table) use ($products) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique('acct_stock_alerts_product_unique');
                $table->string('level', 20);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->dateTime('entered_at');
                $table->dateTime('level_changed_at');
                $table->timestamps();

                $table->foreign('product_id', 'acct_stock_alerts_product_fk')->references('id')->on($products)->cascadeOnDelete();
                $table->index('level', 'acct_stock_alerts_level_idx');
            });
        }

        if (! Schema::hasTable($prefix.'stock_limit_changes')) {
            Schema::create($prefix.'stock_limit_changes', function (Blueprint $table) use ($products) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->decimal('old_max_limit', 18, 4)->nullable();
                $table->decimal('old_min_limit_1', 18, 4)->nullable();
                $table->decimal('old_min_limit_2', 18, 4)->nullable();
                $table->decimal('new_max_limit', 18, 4)->nullable();
                $table->decimal('new_min_limit_1', 18, 4)->nullable();
                $table->decimal('new_min_limit_2', 18, 4)->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users', 'id', 'acct_stock_limit_changes_user_fk')->nullOnDelete();
                $table->timestamps();

                $table->foreign('product_id', 'acct_stock_limit_changes_product_fk')->references('id')->on($products)->cascadeOnDelete();
                $table->index(['product_id', 'created_at'], 'acct_stock_limit_changes_product_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'stock_limit_changes');
        Schema::dropIfExists($prefix.'stock_alerts');
    }
};
