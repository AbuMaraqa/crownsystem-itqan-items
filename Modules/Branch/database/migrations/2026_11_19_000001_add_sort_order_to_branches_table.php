<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترتيب يدوي (سحب وإفلات في الجدول) للفروع.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['branches'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $table = config('branch.table_prefix').$name;

            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'sort_order')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedInteger('sort_order')->default(0)->index('branch_'.$name.'_sort_idx'));
                // الترتيب الحالي (الأقدم أولاً) يصبح نقطة البداية.
                DB::table($table)->update(['sort_order' => DB::raw('id')]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $table = config('branch.table_prefix').$name;

            if (Schema::hasColumn($table, 'sort_order')) {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex('branch_'.$name.'_sort_idx');
                    $blueprint->dropColumn('sort_order');
                });
            }
        }
    }
};
