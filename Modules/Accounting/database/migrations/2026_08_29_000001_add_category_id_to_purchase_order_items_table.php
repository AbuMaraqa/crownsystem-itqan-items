<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الصنف يمكن ان يُدرج تحت "مجموعة" (تصنيف) في جدول اصناف طلبية الشراء.
     * حفظ التصنيف على البند نفسه يسمح باعادة بناء رؤوس المجموعات عند فتح الطلبية،
     * دون الحاجة الى صفوف وهمية بلا صنف.
     */
    public function up(): void
    {
        $table = config('accounting.table_prefix').'purchase_order_items';

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('category_id')
                ->nullable()
                ->after('product_id')
                ->constrained(config('items.table_prefix').'categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table = config('accounting.table_prefix').'purchase_order_items';

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropConstrainedForeignId('category_id');
        });
    }
};
