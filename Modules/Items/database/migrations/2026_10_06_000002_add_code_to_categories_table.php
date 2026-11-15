<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رقم التصنيف كما في شجرة النظام القديم، حيث كل عقدة لها رقم (00001) وابناؤها
 * رقمها وبعده تسلسل (00001001). التصنيفات المستوردة رقمها هو معرّفها القديم
 * المحفوظ في slug بصيغة old-cat-{id}.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'categories';

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('code', 50)->nullable()->after('slug')->unique('items_categories_code_unique');
        });

        DB::table($table)
            ->where('slug', 'like', 'old-cat-%')
            ->whereNull('code')
            ->select(['id', 'slug'])
            ->chunkById(1000, function ($categories) use ($table): void {
                foreach ($categories as $category) {
                    if (preg_match('/^old-cat-(\d+)$/', $category->slug, $matches)) {
                        DB::table($table)->where('id', $category->id)->update(['code' => $matches[1]]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'categories', function (Blueprint $blueprint) {
            $blueprint->dropUnique('items_categories_code_unique');
            $blueprint->dropColumn('code');
        });
    }
};
