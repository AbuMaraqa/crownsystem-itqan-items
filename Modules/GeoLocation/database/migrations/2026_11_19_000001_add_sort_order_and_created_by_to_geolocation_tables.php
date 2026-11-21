<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترتيب يدوي (سحب وإفلات) ومن أضاف السجل (عمود «أضيف بواسطة») للدول والمحافظات والمدن والأحياء.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['countries', 'governorates', 'cities', 'districts'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $table = config('geolocation.table_prefix').$name;

            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'sort_order')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0)->index('geo_'.$name.'_sort_idx'));
                // الترتيب الحالي (الأقدم أولاً) يصبح نقطة البداية.
                DB::table($table)->update(['sort_order' => DB::raw('id')]);
            }

            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'created_by')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('created_by')->nullable()
                    ->constrained('users', 'id', 'geo_'.$name.'_created_by_fk')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $table = config('geolocation.table_prefix').$name;

            if (Schema::hasColumn($table, 'created_by')) {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropForeign('geo_'.$name.'_created_by_fk');
                    $blueprint->dropColumn('created_by');
                });
            }

            if (Schema::hasColumn($table, 'sort_order')) {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex('geo_'.$name.'_sort_idx');
                    $blueprint->dropColumn('sort_order');
                });
            }
        }
    }
};
