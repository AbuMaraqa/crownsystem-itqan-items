<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصنف الفرعي في الطلبية التجميعية يُسحب وحده من صنفه الرئيسي في طلبية الشراء،
 * فيُحفظ مصدره ليُعرف ما سُحب من اصناف البند وما بقي منها.
 *
 * فهرس بلا مفتاح اجنبي: حذف القطعة من طلبية الشراء يجب ألا يُفرغ المصدر هنا،
 * لان القطعة التي بلا مصدر تعني سحب البند كاملاً (كما كان قبل هذا العمود).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('accounting.table_prefix').'consolidated_purchase_order_item_pieces';

        if (Schema::hasColumn($table, 'source_purchase_order_item_piece_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('source_purchase_order_item_piece_id')
                ->nullable()
                ->after('consolidated_purchase_order_item_id')
                ->index('acct_consolidated_po_item_pieces_source_piece_idx');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_item_pieces', function (Blueprint $blueprint) {
            $blueprint->dropIndex('acct_consolidated_po_item_pieces_source_piece_idx');
            $blueprint->dropColumn('source_purchase_order_item_piece_id');
        });
    }
};
