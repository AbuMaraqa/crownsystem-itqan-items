<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - الغاء المستند المرحّل (فاتورة، مرتجع، سند) بقيد عكسي: من ألغى ومتى ولماذا.
 * - تسوية الفواتير: المسدَّد والمتبقي بعملة الفاتورة (من تخصيص السندات والمرتجعات)،
 *   والمخصَّص من كل سند/مرتجع، فتُبنى كشوف الحساب واعمار الديون منها مباشرة.
 * - كلفة الوحدة على سطور المرتجعات (بالعملة الاساسية) لعكس المخزون وتكلفة المبيعات بدقة.
 */
return new class extends Migration
{
    private const CANCELLABLE = ['sales_invoices', 'purchase_invoices', 'sales_returns', 'purchase_returns', 'receipt_vouchers', 'payment_vouchers'];

    private const SHORT = [
        'sales_invoices' => 'si',
        'purchase_invoices' => 'pi',
        'sales_returns' => 'sr',
        'purchase_returns' => 'pr',
        'receipt_vouchers' => 'rv',
        'payment_vouchers' => 'pv',
    ];

    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (self::CANCELLABLE as $name) {
            $tableName = $prefix.$name;

            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $name) {
                if (! Schema::hasColumn($tableName, 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }

                if (! Schema::hasColumn($tableName, 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable();
                    $table->foreign('cancelled_by', 'acct_'.self::SHORT[$name].'_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
                }

                if (! Schema::hasColumn($tableName, 'cancellation_reason')) {
                    $table->string('cancellation_reason')->nullable();
                }

                if (in_array($name, ['sales_invoices', 'purchase_invoices'], true)) {
                    if (! Schema::hasColumn($tableName, 'paid_amount')) {
                        $table->decimal('paid_amount', 18, 4)->default(0);
                    }

                    if (! Schema::hasColumn($tableName, 'outstanding_amount')) {
                        $table->decimal('outstanding_amount', 18, 4)->default(0);
                        $table->index(['client_id', 'outstanding_amount'], 'acct_'.self::SHORT[$name].'_client_outstanding_idx');
                    }
                } elseif (! Schema::hasColumn($tableName, 'allocated_amount')) {
                    $table->decimal('allocated_amount', 18, 4)->default(0);
                }
            });
        }

        foreach (['sales_return_items', 'purchase_return_items'] as $name) {
            $tableName = $prefix.$name;

            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'unit_cost')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->decimal('unit_cost', 20, 6)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        //
    }
};
