<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;

/**
 * كتابة الباركودات الاضافية المستخرجة من النظام القديم. تكرار الباركود بين الاصناف
 * طبيعي في البيانات الحقيقية (المقطع يتكرر تحت كل لون)، فلا يُرفض ولا يُتجاوز. ما هو
 * موجود للصنف نفسه لا يتكرر، فيبقى التشغيل المتكرر آمناً. sort_order يحفظ موضع
 * الباركود الاصلي، لان طباعة الالمنيوم تختار الباركود بموضعه.
 */
class LegacyBarcodeWriter
{
    /**
     * ما يمكن ادراجه، بلا كتابة (يصلح للتشغيل التجريبي).
     *
     * @param  array<int, array<int, array{barcode: string, pack_quantity: string, is_prefix: bool, sort_order: int}>>  $byProduct  الباركودات لكل صنف
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $byProduct): array
    {
        if ($byProduct === []) {
            return [];
        }

        $existing = [];

        foreach (array_chunk(array_keys($byProduct), 1000) as $chunk) {
            DB::table(config('items.table_prefix').'product_barcodes')->whereIn('product_id', $chunk)->get(['product_id', 'barcode'])
                ->each(function (object $row) use (&$existing): void {
                    $existing[(int) $row->product_id][mb_strtolower($row->barcode)] = true;
                });
        }

        $now = now();
        $planned = [];

        foreach ($byProduct as $productId => $rows) {
            foreach ($rows as $row) {
                if (isset($existing[(int) $productId][mb_strtolower($row['barcode'])])) {
                    continue;
                }

                $planned[] = [
                    'product_id' => (int) $productId,
                    'barcode' => $row['barcode'],
                    'pack_quantity' => $row['pack_quantity'],
                    'is_prefix' => $row['is_prefix'],
                    'sort_order' => $row['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $planned;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return int عدد ما أُدرج فعلاً
     */
    public function insert(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table(config('items.table_prefix').'product_barcodes')->insertOrIgnore($chunk);
        }

        return $inserted;
    }
}
