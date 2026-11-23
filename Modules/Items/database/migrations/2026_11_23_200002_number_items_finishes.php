<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أرقام الألوان بنمط النظام القديم (001، 002 ...): الألوان القائمة بلا رقم تُرقَّم بترتيب عرضها،
 * و«بلا لون» النظامي (رمزه كان «none») أولها. الرقم بعدها فريد، ويقترحه النظام للون الجديد
 * (ItemCode::nextFinish) ويمكن تغييره.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'finishes';

        if (! Schema::hasTable($table)) {
            return;
        }

        $codes = DB::table($table)->pluck('code')->filter(fn ($code): bool => $code !== null && ctype_digit((string) $code));
        $next = (int) ($codes->map(fn ($code): int => (int) $code)->max() ?? 0);

        DB::table($table)
            ->where(fn ($query) => $query->whereNull('code')->orWhere('code', '')->orWhere(fn ($query) => $query->where('is_system', true)->where('code', 'none')))
            ->orderByDesc('is_system')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($table, &$next): void {
                DB::table($table)->where('id', $id)->update(['code' => str_pad((string) ++$next, 3, '0', STR_PAD_LEFT)]);
            });

        if (! Schema::hasIndex($table, 'items_finishes_code_unique')) {
            Schema::table($table, fn ($blueprint) => $blueprint->unique('code', 'items_finishes_code_unique'));
        }
    }

    public function down(): void
    {
        $table = config('items.table_prefix').'finishes';

        if (Schema::hasIndex($table, 'items_finishes_code_unique')) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropUnique('items_finishes_code_unique'));
        }
    }
};
