<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Support\Search\SearchCapabilities;

/**
 * فهارس البحث في الاصناف على PostgreSQL.
 *
 * في MySQL يخدم البحثَ فهرسُ FULLTEXT، وفهرس BTREE يخدم LIKE 'نص%'. اما في PostgreSQL
 * فلا FULLTEXT، وفهرس BTREE بترتيب لغة (en_US) لا يخدم LIKE، فيمر كل بحث على جدول
 * الاصناف كله (مئات الآلاف). لذلك:
 *  - فهرس trigram (pg_trgm + GIN) على مستند البحث: يخدم LIKE '% كلمة%' و '%نص%'.
 *  - فهارس text_pattern_ops على الرقم والباركود والاسم: تخدم LIKE 'نص%' (بداية النص).
 *
 * لا يغيّر في MySQL شيئاً. الاستعلامات نفسها لا تتغير: المخطِّط يختار الفهارس وحده.
 */
return new class extends Migration
{
    /**
     * اسم الفهرس => [الجدول بلا بادئة، تعريف الاعمدة].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PATTERN_INDEXES = [
        'items_products_sku_pattern_idx' => ['products', 'sku text_pattern_ops'],
        'items_products_barcode_pattern_idx' => ['products', 'barcode text_pattern_ops'],
        'items_product_trans_name_pattern_idx' => ['product_translations', 'locale, name text_pattern_ops'],
        'items_product_barcodes_pattern_idx' => ['product_barcodes', 'barcode text_pattern_ops'],
    ];

    private const TRIGRAM_INDEX = 'items_product_search_document_trgm_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $prefix = config('items.table_prefix');

        // pg_trgm امتداد موثوق (trusted): ينشئه صاحب صلاحية CREATE على القاعدة دون مدير.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        if (Schema::hasTable(SearchCapabilities::productTable())) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops)',
                self::TRIGRAM_INDEX,
                SearchCapabilities::productTable(),
                SearchCapabilities::DOCUMENT
            ));
            DB::statement('ANALYZE '.SearchCapabilities::productTable());
        }

        foreach (self::PATTERN_INDEXES as $index => [$table, $columns]) {
            if (Schema::hasTable($prefix.$table)) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$prefix}{$table} ({$columns})");
                DB::statement("ANALYZE {$prefix}{$table}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // الامتداد يبقى: قد يستعمله غير هذه الفهارس.
        foreach ([self::TRIGRAM_INDEX, ...array_keys(self::PATTERN_INDEXES)] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
