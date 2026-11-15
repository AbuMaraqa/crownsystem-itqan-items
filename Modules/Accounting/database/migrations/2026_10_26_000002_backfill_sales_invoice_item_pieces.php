<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فواتير البيع المنشأة من طلبية قبل اضافة الاصناف الفرعية للفاتورة: تُنسخ اصناف سطر
 * الطلبية الفرعية الى سطر الفاتورة. الكمية المفوترة (كغم) لا تتغير؛ ان خالفت مجموع اوزان
 * القطع حُفظت وزناً فعلياً حتى يبقى اجمالي السطر كما هو. السطور التي لها اصناف فرعية
 * تُترك، فاعادة التشغيل لا تكرر شيئا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        $lines = DB::table($prefix.'sales_invoice_items as invoice_items')
            ->whereNotNull('invoice_items.sales_order_item_id')
            ->whereNull('invoice_items.deleted_at')
            ->whereNotExists(fn ($query) => $query->from($prefix.'sales_invoice_item_pieces')->whereColumn('sales_invoice_item_id', 'invoice_items.id'))
            ->whereExists(fn ($query) => $query->from($prefix.'sales_order_item_pieces')->whereColumn('sales_order_item_id', 'invoice_items.sales_order_item_id'))
            ->get(['invoice_items.id', 'invoice_items.sales_order_item_id', 'invoice_items.quantity']);

        foreach ($lines as $line) {
            $pieces = DB::table($prefix.'sales_order_item_pieces')
                ->where('sales_order_item_id', $line->sales_order_item_id)
                ->orderBy('sort_order')
                ->get();

            DB::table($prefix.'sales_invoice_item_pieces')->insert($pieces->map(fn ($piece) => [
                'sales_invoice_item_id' => $line->id,
                'sales_order_item_piece_id' => $piece->id,
                'product_id' => $piece->product_id,
                'barcode' => $piece->barcode,
                'description' => $piece->description,
                'piece_count' => $piece->piece_count,
                'piece_length' => $piece->piece_length,
                'weight_per_meter' => $piece->weight_per_meter,
                'total_weight' => $piece->total_weight,
                'sort_order' => $piece->sort_order,
                'created_by' => $piece->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            $totalWeight = round((float) $pieces->sum('total_weight'), 4);

            DB::table($prefix.'sales_invoice_items')->where('id', $line->id)->update([
                'total_weight' => $totalWeight,
                'actual_weight' => abs($totalWeight - (float) $line->quantity) > 0.0001 ? $line->quantity : null,
            ]);
        }
    }

    public function down(): void
    {
        // النسخ لا يُعكس: الاصناف الفرعية جزء من الفاتورة بعد اليوم.
    }
};
