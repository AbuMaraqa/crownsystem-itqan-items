<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود الخدمات في طلبية البيع وفاتورتها: نوع البند (سلعة او خدمة)، اساس التسعير
 * (كمية، كغم، نقلة)، وتكلفة الخدمة ومصدرها والجهة المنفذة ان كانت خارجية.
 * المرتجع يحمل نوع البند فقط، لان التكلفة تُعترف بها عند فوترة الخدمة.
 */
return new class extends Migration
{
    private const TABLES = ['sales_order_items', 'sales_invoice_items'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table(config('accounting.table_prefix').$name, function (Blueprint $table) use ($name) {
                $table->string('line_type', 20)->default('goods')->after('product_id');
                $table->string('pricing_basis', 20)->nullable()->after('line_type');
                $table->decimal('unit_cost', 18, 4)->default(0)->after('line_total');
                $table->decimal('cost_total', 18, 4)->default(0)->after('unit_cost');
                $table->string('cost_source', 20)->nullable()->after('cost_total');
                $table->foreignId('service_provider_id')
                    ->nullable()
                    ->after('cost_source')
                    ->constrained('users', 'id', 'acct_'.$name.'_provider_fk')
                    ->nullOnDelete();
            });
        }

        Schema::table(config('accounting.table_prefix').'sales_return_items', function (Blueprint $table) {
            $table->string('line_type', 20)->default('goods')->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'sales_return_items', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });

        foreach (self::TABLES as $name) {
            Schema::table(config('accounting.table_prefix').$name, function (Blueprint $table) use ($name) {
                $table->dropForeign('acct_'.$name.'_provider_fk');
                $table->dropColumn(['line_type', 'pricing_basis', 'unit_cost', 'cost_total', 'cost_source', 'service_provider_id']);
            });
        }
    }
};
