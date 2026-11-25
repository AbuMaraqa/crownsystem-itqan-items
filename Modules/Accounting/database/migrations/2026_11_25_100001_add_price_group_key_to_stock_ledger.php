<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حساب اتقان بلا صنف رئيسي وهمي: رصيد الكيلو وقيمته على (التصنيف × اللون) مستقلاً عن عدد القطع.
 * فدفتر المخزون يُكتب بأحد مفتاحين: الصنف (product_id) او مجموعة السعر (category_finish_id).
 */
return new class extends Migration
{
    /**
     * @var array<string, array{fk: string, unique: ?string, index: ?array{0: array<int, string>, 1: string}}>
     */
    private function tables(): array
    {
        $prefix = config('accounting.table_prefix');

        return [
            $prefix.'stock_movements' => ['fk' => 'acct_stock_moves_group_fk', 'unique' => null, 'index' => [['category_finish_id', 'warehouse_id', 'movement_date'], 'acct_stock_moves_group_idx']],
            $prefix.'stock_balances' => ['fk' => 'acct_stock_bal_group_fk', 'unique' => null, 'index' => null],
            $prefix.'stock_cost_layers' => ['fk' => 'acct_stock_layers_group_fk', 'unique' => null, 'index' => [['category_finish_id', 'warehouse_id'], 'acct_stock_layers_group_idx']],
            $prefix.'product_costs' => ['fk' => 'acct_product_costs_group_fk', 'unique' => 'acct_product_costs_group_unique', 'index' => null],
        ];
    }

    public function up(): void
    {
        $groups = config('items.table_prefix').'category_finishes';
        $balances = config('accounting.table_prefix').'stock_balances';

        foreach ($this->tables() as $table => $keys) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'category_finish_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($groups, $keys) {
                $blueprint->unsignedBigInteger('product_id')->nullable()->change();
                $blueprint->foreignId('category_finish_id')->nullable()->after('product_id')
                    ->constrained($groups, 'id', $keys['fk'])->restrictOnDelete();

                if ($keys['unique']) {
                    $blueprint->unique('category_finish_id', $keys['unique']);
                }

                if ($keys['index']) {
                    $blueprint->index($keys['index'][0], $keys['index'][1]);
                }
            });
        }

        if (Schema::hasTable($balances) && Schema::hasColumn($balances, 'category_finish_id')) {
            Schema::table($balances, fn (Blueprint $blueprint) => $blueprint->unique(['category_finish_id', 'warehouse_id', 'location_id'], 'acct_stock_bal_group_unique'));
        }
    }

    public function down(): void
    {
        $balances = config('accounting.table_prefix').'stock_balances';

        if (Schema::hasColumn($balances, 'category_finish_id')) {
            Schema::table($balances, fn (Blueprint $blueprint) => $blueprint->dropUnique('acct_stock_bal_group_unique'));
        }

        foreach ($this->tables() as $table => $keys) {
            if (! Schema::hasColumn($table, 'category_finish_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($keys) {
                if ($keys['unique']) {
                    $blueprint->dropUnique($keys['unique']);
                }

                if ($keys['index']) {
                    $blueprint->dropIndex($keys['index'][1]);
                }

                $blueprint->dropForeign($keys['fk']);
                $blueprint->dropColumn('category_finish_id');
            });
        }
    }
};
