<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فواتير الشراء المنشأة من طلبية قبل اضافة الاصناف الفرعية للفاتورة: تُنسخ اصناف سطر
 * الطلبية الفرعية الى سطر الفاتورة (ووزنها الكلي)، فتظهر الفاتورة كالطلبية. السطور التي
 * لها اصناف فرعية تُترك، فاعادة التشغيل لا تكرر شيئا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        $lines = DB::table($prefix.'purchase_invoice_items as invoice_items')
            ->whereNotNull('invoice_items.purchase_order_item_id')
            ->whereNull('invoice_items.deleted_at')
            ->whereNotExists(fn ($query) => $query->from($prefix.'purchase_invoice_item_pieces')->whereColumn('purchase_invoice_item_id', 'invoice_items.id'))
            ->whereExists(fn ($query) => $query->from($prefix.'purchase_order_item_pieces')->whereColumn('purchase_order_item_id', 'invoice_items.purchase_order_item_id'))
            ->get(['invoice_items.id', 'invoice_items.purchase_order_item_id']);

        foreach ($lines as $line) {
            $pieces = DB::table($prefix.'purchase_order_item_pieces')
                ->where('purchase_order_item_id', $line->purchase_order_item_id)
                ->orderBy('sort_order')
                ->get();

            DB::table($prefix.'purchase_invoice_item_pieces')->insert($pieces->map(fn ($piece) => [
                'purchase_invoice_item_id' => $line->id,
                'purchase_order_item_piece_id' => $piece->id,
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

            DB::table($prefix.'purchase_invoice_items')->where('id', $line->id)->update([
                'total_weight' => round((float) $pieces->sum('total_weight'), 4),
            ]);
        }
    }

    public function down(): void
    {
        // النسخ لا يُعكس: الاصناف الفرعية جزء من الفاتورة بعد اليوم.
    }
};
