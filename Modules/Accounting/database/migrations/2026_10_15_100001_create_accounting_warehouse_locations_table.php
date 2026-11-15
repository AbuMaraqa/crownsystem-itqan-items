<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مواقع التخزين داخل المخزن كما هي على ارض الشركة:
 *   الطابق/المنطقة (اختياري) ← الممر (الحرف على الجسر الازرق: A … T، AA … AJ)
 *   ← رقم الموقع (الرقم على العمود: 1 … 12).
 * رمز الموقع = الممر + الرقم (مثل E10 او AG7)، وهو نفس «رقم الرف» في النظام القديم.
 *
 * product_locations: موقع كل صنف في كل مخزن. للصنف موقع اساسي ثابت (عليه بطاقة الرف)،
 * ومواقع اضافية حين لا يتسع موقعه الاساسي.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        if (! Schema::hasTable($prefix.'warehouse_locations')) {
            Schema::create($prefix.'warehouse_locations', function (Blueprint $table) use ($prefix) {
                $table->id();
                $table->foreignId('warehouse_id')
                    ->constrained($prefix.'warehouses', 'id', 'acct_wh_locations_warehouse_fk')
                    ->cascadeOnDelete();
                $table->string('zone', 60)->nullable();
                $table->string('aisle', 20);
                $table->unsignedSmallInteger('position')->nullable();
                $table->string('code', 40);
                $table->string('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_wh_locations_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->unique(['warehouse_id', 'code'], 'acct_wh_locations_code_unique');
                $table->index(['warehouse_id', 'zone', 'aisle', 'position'], 'acct_wh_locations_grid_idx');
            });
        }

        if (! Schema::hasTable($prefix.'product_locations')) {
            Schema::create($prefix.'product_locations', function (Blueprint $table) use ($prefix) {
                $table->id();
                $table->foreignId('product_id')
                    ->constrained(config('items.table_prefix').'products', 'id', 'acct_product_locations_product_fk')
                    ->cascadeOnDelete();
                $table->foreignId('warehouse_id')
                    ->constrained($prefix.'warehouses', 'id', 'acct_product_locations_warehouse_fk')
                    ->cascadeOnDelete();
                $table->foreignId('location_id')
                    ->constrained($prefix.'warehouse_locations', 'id', 'acct_product_locations_location_fk')
                    ->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->string('direction', 10)->nullable();
                $table->string('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_product_locations_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->unique(['product_id', 'location_id'], 'acct_product_locations_unique');
                $table->index(['product_id', 'warehouse_id', 'is_primary'], 'acct_product_locations_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'product_locations');
        Schema::dropIfExists($prefix.'warehouse_locations');
    }
};
