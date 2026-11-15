<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\SalesQuotationStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_quotations';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'warehouses')
                ->nullOnDelete();
            $table->string('reference_no')->unique('acct_sales_quotation_reference_unique');
            $table->unsignedTinyInteger('status')->default(SalesQuotationStatus::DRAFT->value());
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'taxes')
                ->nullOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('sub_total', 18, 4)->default(0);
            $table->decimal('items_discount', 18, 4)->default(0);
            $table->decimal('quotation_discount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->timestamp('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['quotation_date', 'currency_id'], 'acct_sales_quote_date_currency_idx');
            $table->index(['status', 'quotation_date'], 'acct_sales_quote_status_date_idx');
            $table->index(['client_id', 'quotation_date'], 'acct_sales_quote_client_date_idx');
            $table->index('warehouse_id', 'acct_sales_quote_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_quotations');
    }
};
