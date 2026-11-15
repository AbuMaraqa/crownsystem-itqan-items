<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطر صنف الوزن في فاتورة الشراء = الصنف الرئيسي، وتحته اصنافه الفرعية (قطع) كما في
 * طلبية الشراء: وزنه مجموع اوزانها، او الوزن الفعلي من الميزان ان أُدخل.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::table($prefix.'purchase_invoice_items', function (Blueprint $table) use ($prefix) {
            if (! Schema::hasColumn($prefix.'purchase_invoice_items', 'total_weight')) {
                $table->decimal('total_weight', 18, 4)->default(0)->after('quantity');
                $table->decimal('actual_weight', 18, 4)->nullable()->after('total_weight');
            }
        });

        if (Schema::hasTable($prefix.'purchase_invoice_item_pieces')) {
            return;
        }

        Schema::create($prefix.'purchase_invoice_item_pieces', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('purchase_invoice_item_id');
            $table->foreign('purchase_invoice_item_id', 'acct_pi_item_pieces_item_fk')
                ->references('id')
                ->on($prefix.'purchase_invoice_items')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_item_piece_id')->nullable();
            $table->foreign('purchase_order_item_piece_id', 'acct_pi_item_pieces_po_piece_fk')
                ->references('id')
                ->on($prefix.'purchase_order_item_pieces')
                ->nullOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('description')->nullable();
            $table->decimal('piece_count', 18, 4)->default(1);
            $table->decimal('piece_length', 18, 4)->nullable();
            $table->decimal('weight_per_meter', 18, 4)->nullable();
            $table->decimal('total_weight', 18, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_invoice_item_id', 'product_id'], 'acct_pi_item_pieces_item_product_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'purchase_invoice_item_pieces');

        Schema::table($prefix.'purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['total_weight', 'actual_weight']);
        });
    }
};
