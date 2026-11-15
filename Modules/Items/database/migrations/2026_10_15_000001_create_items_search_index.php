<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;

/**
 * فهرس البحث في الاصناف: جدول ضيّق مستقل (المعرّف والمستند فقط) عليه فهرس
 * FULLTEXT. ضيقه يجعل الكتابة فيه والمرور عليه بـ LIKE اسرع بكثير من عمود في
 * جدول الاصناف العريض. وللتصنيفات (وهي قليلة) عمود search_text في جدولها.
 *
 * ثم تُبنى المستندات. اعادة البناء لاحقاً: php artisan items:search-index
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        if (! Schema::hasTable(SearchCapabilities::productTable())) {
            Schema::create(SearchCapabilities::productTable(), function (Blueprint $table) use ($prefix) {
                $table->foreignId('product_id')->primary()->constrained($prefix.'products')->cascadeOnDelete();
                $table->text(SearchCapabilities::DOCUMENT);
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! $this->hasFullTextIndex()) {
            DB::statement('CREATE FULLTEXT INDEX '.SearchCapabilities::FULLTEXT_INDEX
                .' ON '.DB::getTablePrefix().SearchCapabilities::productTable().' (`'.SearchCapabilities::DOCUMENT.'`)');
        }

        if (! Schema::hasColumn($prefix.'categories', SearchCapabilities::CATEGORY_COLUMN)) {
            Schema::table($prefix.'categories', function (Blueprint $table) {
                $table->text(SearchCapabilities::CATEGORY_COLUMN)->nullable();
            });
        }

        SearchCapabilities::forget();

        app(ItemsSearchIndex::class)->rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists(SearchCapabilities::productTable());

        $prefix = config('items.table_prefix');

        if (Schema::hasColumn($prefix.'categories', SearchCapabilities::CATEGORY_COLUMN)) {
            Schema::table($prefix.'categories', function (Blueprint $table) {
                $table->dropColumn(SearchCapabilities::CATEGORY_COLUMN);
            });
        }

        SearchCapabilities::forget();
    }

    private function hasFullTextIndex(): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', DB::getTablePrefix().SearchCapabilities::productTable())
            ->where('index_name', SearchCapabilities::FULLTEXT_INDEX)
            ->exists();
    }
};
