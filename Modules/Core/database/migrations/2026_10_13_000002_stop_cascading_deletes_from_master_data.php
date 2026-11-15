<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح البيانات الاساسية كانت ON DELETE CASCADE في سلسلة واحدة:
 * عملة ← دول ← محافظات ← مدن ← فروع ← مستخدمون ← وحدات وعلامات تجارية...
 * فحذف عملة او مدينة كان يحذف المستخدمين وما انشأوه. تصير RESTRICT (يُمنع حذف المستخدَم)
 * و SET NULL لفرع المستخدم.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function foreignKeys(): array
    {
        $items = config('items.table_prefix', 'items_');
        $branch = config('branch.table_prefix', 'branch_');
        $geo = config('geolocation.table_prefix', 'geolocation_');

        return [
            // [الجدول، العمود، الجدول المرجعي، القاعدة الجديدة]
            ['users', 'branch_id', $branch.'branches', 'nullOnDelete'],
            [$branch.'branches', 'city_id', $geo.'cities', 'restrictOnDelete'],
            [$branch.'branches', 'country_id', $geo.'countries', 'restrictOnDelete'],
            [$branch.'branches', 'created_by', 'users', 'restrictOnDelete'],
            [$geo.'countries', 'currency_id', 'currencies', 'restrictOnDelete'],
            [$geo.'governorates', 'country_id', $geo.'countries', 'restrictOnDelete'],
            [$geo.'cities', 'governorate_id', $geo.'governorates', 'restrictOnDelete'],
            ['core_transactions', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'units', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'brands', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'manufacturers', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'addons', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'addon_product', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'offers', 'created_by', 'users', 'restrictOnDelete'],
            [$items.'orders', 'created_by', 'users', 'restrictOnDelete'],
        ];
    }

    public function up(): void
    {
        foreach ($this->foreignKeys() as [$table, $column, $references, $onDelete]) {
            $this->replaceForeignKey($table, $column, $references, $onDelete);
        }
    }

    public function down(): void
    {
        foreach ($this->foreignKeys() as [$table, $column, $references]) {
            $this->replaceForeignKey($table, $column, $references, 'cascadeOnDelete');
        }
    }

    private function replaceForeignKey(string $table, string $column, string $references, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($references)) {
            return;
        }

        // اسم القيد يُقرأ من القاعدة لان بعض الترحيلات سمّته باسم غير الافتراضي.
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreignKey['name']));
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($references)->{$onDelete}());
    }
};
