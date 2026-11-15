<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرتجع صنف الوزن يُرجع اصنافاً فرعية بعدد قطع (بحد اقصى ما فوتر من كل قطعة):
 * قطع المرتجع مربوطة بقطع الفاتورة، ولكل قطعة كلفة حركتها في المخزن.
 * مرتجع البيع صار يعيد البضاعة الى المخزن، فلسطره كلفة وحدة كمرتجع الشراء.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{link: string, source_column: string, source_table: string, fk: string}>
     */
    private array $documents = [
        'sales_return' => [
            'link' => 'sales_return_item_id',
            'source_column' => 'sales_invoice_item_piece_id',
            'source_table' => 'sales_invoice_item_pieces',
            'fk' => 'acct_sr_item_pieces',
        ],
        'purchase_return' => [
            'link' => 'purchase_return_item_id',
            'source_column' => 'purchase_invoice_item_piece_id',
            'source_table' => 'purchase_invoice_item_pieces',
            'fk' => 'acct_pr_item_pieces',
        ],
    ];

    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach ($this->documents as $document => $definition) {
            $itemsTable = $prefix.$document.'_items';
            $piecesTable = $prefix.$document.'_item_pieces';

            Schema::table($itemsTable, function (Blueprint $table) use ($itemsTable) {
                if (! Schema::hasColumn($itemsTable, 'total_weight')) {
                    $table->decimal('total_weight', 18, 4)->default(0)->after('quantity');
                }

                if (! Schema::hasColumn($itemsTable, 'unit_cost')) {
                    $table->decimal('unit_cost', 20, 6)->nullable()->after('line_total');
                }
            });

            if (Schema::hasTable($piecesTable)) {
                continue;
            }

            Schema::create($piecesTable, function (Blueprint $table) use ($prefix, $itemsTable, $definition) {
                $table->id();
                $table->foreignId($definition['link']);
                $table->foreign($definition['link'], $definition['fk'].'_item_fk')
                    ->references('id')
                    ->on($itemsTable)
                    ->cascadeOnDelete();
                $table->foreignId($definition['source_column'])->nullable();
                $table->foreign($definition['source_column'], $definition['fk'].'_source_fk')
                    ->references('id')
                    ->on($prefix.$definition['source_table'])
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
                // كلفة القطعة الواحدة بالعملة الاساسية من حركة المخزن عند الترحيل.
                $table->decimal('unit_cost', 20, 6)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index([$definition['link'], 'product_id'], $definition['fk'].'_item_product_idx');
                $table->index($definition['source_column'], $definition['fk'].'_source_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (array_keys($this->documents) as $document) {
            Schema::dropIfExists($prefix.$document.'_item_pieces');

            Schema::table($prefix.$document.'_items', function (Blueprint $table) {
                $table->dropColumn('total_weight');
            });
        }

        Schema::table($prefix.'sales_return_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
