<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مستندات المخزن:
 * - goods_receipts (سند استلام): يُستلم من طلبية شراء الى مخزن، ويقبل الاستلام الجزئي.
 * - delivery_notes (سند تسليم): يُسلَّم من طلبية بيع من مخزن، ويقبل التسليم الجزئي.
 * - stock_transfers (تحويل مخزني): بين مخزنين او بين موقعين في نفس المخزن.
 * - stock_adjustments (رصيد افتتاحي، جرد، تسوية).
 *
 * سطور الاصناف الموزونة تُسجَّل لكل صنف فرعي (قطعة) بعدد قطعه ووزنه.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $products = config('items.table_prefix').'products';
        $branches = config('branch.table_prefix').'branches';

        $documentColumns = function (Blueprint $table, string $short) use ($prefix, $branches) {
            $table->string('reference_no')->unique("acct_{$short}_reference_unique");
            $table->unsignedTinyInteger('status')->default(1);
            $table->foreignId('branch_id')->nullable()->constrained($branches, 'id', "acct_{$short}_branch_fk")->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('total_quantity', 18, 4)->default(0);
            $table->decimal('total_weight', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained($prefix.'journal_entries', 'id', "acct_{$short}_journal_fk")->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users', 'id', "acct_{$short}_posted_by_fk")->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', "acct_{$short}_cancelled_by_fk")->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', "acct_{$short}_created_by_fk")->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        };

        $lineColumns = function (Blueprint $table, string $short) use ($products) {
            $table->foreignId('product_id')->constrained($products, 'id', "acct_{$short}_product_fk")->restrictOnDelete();
            $table->string('barcode')->nullable();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('piece_length', 18, 4)->default(0);
            $table->decimal('weight', 18, 4)->default(0);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->string('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', "acct_{$short}_created_by_fk")->nullOnDelete();
            $table->timestamps();
        };

        if (! Schema::hasTable($prefix.'goods_receipts')) {
            Schema::create($prefix.'goods_receipts', function (Blueprint $table) use ($prefix, $documentColumns) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained($prefix.'purchase_orders', 'id', 'acct_grn_po_fk')->restrictOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('users', 'id', 'acct_grn_supplier_fk')->nullOnDelete();
                $table->foreignId('warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_grn_warehouse_fk')->restrictOnDelete();
                $table->date('receipt_date');
                $table->string('supplier_document_no')->nullable();
                $table->string('container_no')->nullable();
                $table->string('received_by_name')->nullable();
                $documentColumns($table, 'grn');

                $table->index(['purchase_order_id', 'status'], 'acct_grn_po_status_idx');
            });
        }

        if (! Schema::hasTable($prefix.'goods_receipt_items')) {
            Schema::create($prefix.'goods_receipt_items', function (Blueprint $table) use ($prefix, $lineColumns) {
                $table->id();
                $table->foreignId('goods_receipt_id')->constrained($prefix.'goods_receipts', 'id', 'acct_grn_items_grn_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index('acct_grn_items_po_item_idx');
                $table->unsignedBigInteger('purchase_order_item_piece_id')->nullable()->index('acct_grn_items_po_piece_idx');
                $table->unsignedBigInteger('location_id')->nullable();
                $lineColumns($table, 'grn_items');
            });
        }

        if (! Schema::hasTable($prefix.'delivery_notes')) {
            Schema::create($prefix.'delivery_notes', function (Blueprint $table) use ($prefix, $documentColumns) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained($prefix.'sales_orders', 'id', 'acct_dn_so_fk')->restrictOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('users', 'id', 'acct_dn_customer_fk')->nullOnDelete();
                $table->foreignId('warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_dn_warehouse_fk')->restrictOnDelete();
                $table->date('delivery_date');
                $table->string('driver_name')->nullable();
                $table->string('vehicle_no')->nullable();
                $table->string('receiver_name')->nullable();
                $table->string('delivery_address')->nullable();
                $documentColumns($table, 'dn');

                $table->index(['sales_order_id', 'status'], 'acct_dn_so_status_idx');
            });
        }

        if (! Schema::hasTable($prefix.'delivery_note_items')) {
            Schema::create($prefix.'delivery_note_items', function (Blueprint $table) use ($prefix, $lineColumns) {
                $table->id();
                $table->foreignId('delivery_note_id')->constrained($prefix.'delivery_notes', 'id', 'acct_dn_items_dn_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('sales_order_item_id')->nullable()->index('acct_dn_items_so_item_idx');
                $table->unsignedBigInteger('sales_order_item_piece_id')->nullable()->index('acct_dn_items_so_piece_idx');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->json('picked_locations')->nullable();
                $lineColumns($table, 'dn_items');
            });
        }

        if (! Schema::hasTable($prefix.'stock_transfers')) {
            Schema::create($prefix.'stock_transfers', function (Blueprint $table) use ($prefix, $documentColumns) {
                $table->id();
                $table->foreignId('from_warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_st_from_wh_fk')->restrictOnDelete();
                $table->foreignId('to_warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_st_to_wh_fk')->restrictOnDelete();
                $table->date('transfer_date');
                $documentColumns($table, 'st');
            });
        }

        if (! Schema::hasTable($prefix.'stock_transfer_items')) {
            Schema::create($prefix.'stock_transfer_items', function (Blueprint $table) use ($prefix, $lineColumns) {
                $table->id();
                $table->foreignId('stock_transfer_id')->constrained($prefix.'stock_transfers', 'id', 'acct_st_items_st_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('from_location_id')->nullable();
                $table->unsignedBigInteger('to_location_id')->nullable();
                $lineColumns($table, 'st_items');
            });
        }

        if (! Schema::hasTable($prefix.'stock_adjustments')) {
            Schema::create($prefix.'stock_adjustments', function (Blueprint $table) use ($prefix, $documentColumns) {
                $table->id();
                $table->string('type', 20);
                $table->foreignId('warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_sa_warehouse_fk')->restrictOnDelete();
                $table->date('adjustment_date');
                $table->string('reason')->nullable();
                $documentColumns($table, 'sa');
            });
        }

        if (! Schema::hasTable($prefix.'stock_adjustment_items')) {
            Schema::create($prefix.'stock_adjustment_items', function (Blueprint $table) use ($prefix, $lineColumns) {
                $table->id();
                $table->foreignId('stock_adjustment_id')->constrained($prefix.'stock_adjustments', 'id', 'acct_sa_items_sa_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->decimal('system_quantity', 18, 4)->nullable();
                $table->decimal('counted_quantity', 18, 4)->nullable();
                $lineColumns($table, 'sa_items');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (['stock_adjustment_items', 'stock_adjustments', 'stock_transfer_items', 'stock_transfers', 'delivery_note_items', 'delivery_notes', 'goods_receipt_items', 'goods_receipts'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }
};
