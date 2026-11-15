<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;

/**
 * الاسم الانجليزي للصنف القديم (Prop6) في ترجمة en. يُكتب للصنف الذي لا ترجمة en له
 * فقط، فلا يمحو تعديلاً يدوياً ولا يتكرر عند اعادة التشغيل.
 */
class LegacyEnglishNameWriter
{
    /**
     * ما يمكن ادراجه، بلا كتابة.
     *
     * @param  array<int, string>  $names  الاسم لكل صنف
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $table = config('items.table_prefix').'product_translations';

        foreach (array_chunk(array_keys($names), 1000) as $chunk) {
            DB::table($table)->whereIn('product_id', $chunk)->where('locale', 'en')->pluck('product_id')
                ->each(function ($id) use (&$names): void {
                    unset($names[(int) $id]);
                });
        }

        $now = now();
        $rows = [];

        foreach ($names as $productId => $name) {
            $rows[] = ['product_id' => (int) $productId, 'locale' => 'en', 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insert(array $rows): int
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(config('items.table_prefix').'product_translations')->insert($chunk);
        }

        return count($rows);
    }
}
