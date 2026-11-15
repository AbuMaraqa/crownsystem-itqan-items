<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لون الصنف. يُكتب على نوع الالمنيوم (الصنف الرئيسي) وتأخذه اصنافه الفرعية منه،
 * وبه تُجمَّع اصناف الالمنيوم في قسم متابعة المخزون.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'products';

        if (Schema::hasColumn($table, 'color')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('color', 50)->nullable()->after('shelf');
            $blueprint->index('color', 'items_products_color_idx');
        });
    }

    public function down(): void
    {
        $table = config('items.table_prefix').'products';

        if (Schema::hasColumn($table, 'color')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex('items_products_color_idx');
                $blueprint->dropColumn('color');
            });
        }
    }
};
