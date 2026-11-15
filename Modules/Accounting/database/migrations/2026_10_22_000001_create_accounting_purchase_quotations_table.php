<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عرض سعر الشراء: سعر المورد لطلبية الشراء. الطلبية تحدد ما يُشترى وكمياته بلا اسعار،
 * والعرض يسعّر سطورها (عرض واحد فعّال لكل طلبية)، ومن العرض المعتمد تُنشأ فاتورة الشراء.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $tableName = $prefix.'purchase_quotations';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($prefix) {
                $table->id();
                $table->foreignId('purchase_order_id')
                    ->constrained($prefix.'purchase_orders', 'id', 'acct_pquo_purchase_order_fk')
                    ->restrictOnDelete();
                $table->foreignId('client_id')
                    ->constrained('users', 'id', 'acct_pquo_client_fk')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->foreign('branch_id', 'acct_pquo_branch_fk')
                    ->references('id')
                    ->on(config('branch.table_prefix').'branches')
                    ->nullOnDelete();
                $table->string('reference_no')->unique('acct_pquo_reference_unique');
                $table->string('supplier_reference')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->date('quotation_date');
                $table->date('valid_until')->nullable();
                $table->foreignId('currency_id')
                    ->constrained('currencies', 'id', 'acct_pquo_currency_fk')
                    ->restrictOnDelete();
                $table->foreignId('tax_id')
                    ->nullable()
                    ->constrained($prefix.'taxes', 'id', 'acct_pquo_tax_fk')
                    ->nullOnDelete();
                $table->decimal('exchange_rate', 18, 8)->default(1);
                $table->decimal('sub_total', 18, 4)->default(0);
                $table->decimal('items_discount', 18, 4)->default(0);
                $table->decimal('quotation_discount', 18, 4)->default(0);
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->decimal('tax_amount', 18, 4)->default(0);
                $table->decimal('total_amount', 18, 4)->default(0);
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')
                    ->nullable()
                    ->constrained('users', 'id', 'acct_pquo_approved_by_fk')
                    ->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users', 'id', 'acct_pquo_cancelled_by_fk')
                    ->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users', 'id', 'acct_pquo_created_by_fk')
                    ->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['purchase_order_id', 'status'], 'acct_pquo_order_status_idx');
                $table->index('quotation_date', 'acct_pquo_date_idx');
            });
        }

        $itemsTable = $prefix.'purchase_quotation_items';

        if (! Schema::hasTable($itemsTable)) {
            Schema::create($itemsTable, function (Blueprint $table) use ($prefix, $tableName) {
                $table->id();
                $table->foreignId('purchase_quotation_id')
                    ->constrained($tableName, 'id', 'acct_pquo_items_quotation_fk')
                    ->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')
                    ->nullable()
                    ->constrained($prefix.'purchase_order_items', 'id', 'acct_pquo_items_order_item_fk')
                    ->nullOnDelete();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->foreign('product_id', 'acct_pquo_items_product_fk')
                    ->references('id')
                    ->on(config('items.table_prefix').'products')
                    ->nullOnDelete();
                $table->string('barcode')->nullable();
                $table->string('description')->nullable();
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('unit_price', 18, 4)->default(0);
                $table->decimal('discount_rate', 8, 4)->default(0);
                $table->decimal('discount_amount', 18, 4)->default(0);
                $table->decimal('line_total', 18, 4)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users', 'id', 'acct_pquo_items_created_by_fk')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn($prefix.'purchase_invoices', 'purchase_quotation_id')) {
            Schema::table($prefix.'purchase_invoices', function (Blueprint $table) use ($tableName) {
                $table->foreignId('purchase_quotation_id')
                    ->nullable()
                    ->after('purchase_order_id')
                    ->constrained($tableName, 'id', 'acct_pinv_purchase_quotation_fk')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'purchase_invoices', 'purchase_quotation_id')) {
            Schema::table($prefix.'purchase_invoices', function (Blueprint $table) {
                $table->dropForeign('acct_pinv_purchase_quotation_fk');
                $table->dropColumn('purchase_quotation_id');
            });
        }

        Schema::dropIfExists($prefix.'purchase_quotation_items');
        Schema::dropIfExists($prefix.'purchase_quotations');
    }
};
