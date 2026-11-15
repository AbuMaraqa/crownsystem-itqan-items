<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طبقات تكلفة FIFO كما في اتقان (Cost-System=FIFO): لكل دخول طبقة، والخروج يستهلك الاقدم في
 * نفس المخزن، وكل استهلاك مربوط بحركته فيعود كما كان عند العكس.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::create($prefix.'stock_cost_layers', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('product_id')->constrained(config('items.table_prefix').'products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained($prefix.'warehouses')->cascadeOnDelete();
            // حركة الدخول التي انشأت الطبقة؛ null لطبقة الافتتاح من الارصدة السابقة.
            $table->foreignId('stock_movement_id')->nullable()->constrained($prefix.'stock_movements')->nullOnDelete();
            // ترتيب الاستهلاك: رقم الطبقة نفسها، والنقل يرث ترتيب الطبقة التي جاء منها.
            $table->unsignedBigInteger('sequence')->default(0);
            $table->decimal('quantity', 18, 4);
            $table->decimal('remaining_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('remaining_value', 18, 4);
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'sequence'], 'stock_cost_layers_fifo_index');
        });

        Schema::create($prefix.'stock_cost_layer_entries', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('stock_cost_layer_id')->constrained($prefix.'stock_cost_layers')->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained($prefix.'stock_movements')->cascadeOnDelete();
            // issue: استهلاك كمية وقيمة. revalue: تعديل قيمة بلا كمية مع السعر السابق للعكس.
            $table->string('kind', 10)->default('issue');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->decimal('previous_unit_cost', 18, 6)->nullable();
            $table->decimal('remaining_at_entry', 18, 4)->nullable();
            $table->timestamps();

            $table->index(['stock_movement_id', 'kind'], 'stock_cost_layer_entries_movement_index');
        });

        $this->seedOpeningLayers();
    }

    /**
     * طبقة افتتاح واحدة لكل صنف في كل مخزن فيه رصيد، بقيمة ProductCost موزعة بنسبة الكمية،
     * فتبقى قيمة المخزون كما هي. اتقان يستهلك الطبقات داخل المخزن نفسه. المقاطع بلا طبقات: لا
     * قيمة لها في هذا النموذج.
     */
    public function seedOpeningLayers(): void
    {
        $prefix = config('accounting.table_prefix');
        $products = config('items.table_prefix').'products';
        $now = now();

        DB::table($prefix.'product_costs as costs')
            ->join($products.' as products', 'products.id', '=', 'costs.product_id')
            ->where('costs.quantity', '>', 0)
            ->where(fn ($query) => $query->whereNull('products.main_product_id')->orWhere('products.pricing_type', '!=', 'weight'))
            ->orderBy('costs.product_id')
            ->select('costs.product_id', 'costs.value')
            ->chunk(500, function ($costs) use ($prefix, $now) {
                foreach ($costs as $cost) {
                    $balances = DB::table($prefix.'stock_balances')
                        ->where('product_id', $cost->product_id)
                        ->where('quantity', '>', 0)
                        ->groupBy('warehouse_id')
                        ->selectRaw('warehouse_id, SUM(quantity) as quantity')
                        ->orderBy('warehouse_id')
                        ->get();
                    $totalQuantity = (float) $balances->sum('quantity');
                    $remaining = round((float) $cost->value, 4);

                    foreach ($balances->values() as $index => $balance) {
                        $quantity = (float) $balance->quantity;
                        $value = $index === $balances->count() - 1
                            ? $remaining
                            : round((float) $cost->value * $quantity / $totalQuantity, 4);
                        $remaining = round($remaining - $value, 4);

                        $id = DB::table($prefix.'stock_cost_layers')->insertGetId([
                            'product_id' => $cost->product_id,
                            'warehouse_id' => $balance->warehouse_id,
                            'stock_movement_id' => null,
                            'sequence' => 0,
                            'quantity' => $quantity,
                            'remaining_quantity' => $quantity,
                            'unit_cost' => round($value / $quantity, 6),
                            'remaining_value' => $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        DB::table($prefix.'stock_cost_layers')->where('id', $id)->update(['sequence' => $id]);
                    }
                }
            });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'stock_cost_layer_entries');
        Schema::dropIfExists($prefix.'stock_cost_layers');
    }
};
