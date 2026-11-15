<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول اصناف واحد لكل المستندات: سطر صنف الوزن في عرض سعر البيع وفاتورة البيع = الصنف
 * الرئيسي، وتحته اصنافه الفرعية (قطع) كما في الطلبيات؛ وزنه مجموع اوزانها او الوزن الفعلي.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{link: string, source: ?array{column: string, table: string}, fk: string}>
     */
    private array $documents = [
        'sales_quotation' => ['link' => 'sales_quotation_item_id', 'source' => null, 'fk' => 'acct_sq_item_pieces'],
        'sales_invoice' => [
            'link' => 'sales_invoice_item_id',
            'source' => ['column' => 'sales_order_item_piece_id', 'table' => 'sales_order_item_pieces'],
            'fk' => 'acct_si_item_pieces',
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
                    $table->decimal('actual_weight', 18, 4)->nullable()->after('total_weight');
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

                if ($definition['source']) {
                    $table->foreignId($definition['source']['column'])->nullable();
                    $table->foreign($definition['source']['column'], $definition['fk'].'_source_fk')
                        ->references('id')
                        ->on($prefix.$definition['source']['table'])
                        ->nullOnDelete();
                }

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
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index([$definition['link'], 'product_id'], $definition['fk'].'_item_product_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (array_keys($this->documents) as $document) {
            Schema::dropIfExists($prefix.$document.'_item_pieces');

            Schema::table($prefix.$document.'_items', function (Blueprint $table) {
                $table->dropColumn(['total_weight', 'actual_weight']);
            });
        }
    }
};
