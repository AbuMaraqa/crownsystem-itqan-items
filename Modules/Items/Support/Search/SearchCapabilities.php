<?php

namespace Modules\Items\Support\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ما تتيحه قاعدة البيانات للبحث، محفوظاً حتى لا يُسأل عنه في كل طلب:
 * هل بُني جدول فهرس الاصناف، وهل عليه FULLTEXT، واقصر كلمة يفهرسها، وهل
 * في جدول التصنيفات عمود الفهرس.
 *
 * بلا فهرس (قبل تشغيل الترحيل) يبقى البحث بالاسم والرقم والباركود، وبلا
 * FULLTEXT يُبحث في الفهرس بـ LIKE.
 */
final class SearchCapabilities
{
    /**
     * جدول فهرس الاصناف (بلا بادئة الوحدة)، وعمود المستند فيه.
     */
    public const PRODUCT_TABLE = 'product_search';

    public const DOCUMENT = 'document';

    public const FULLTEXT_INDEX = 'items_product_search_document_fulltext';

    public const CATEGORY_COLUMN = 'search_text';

    private const CACHE_KEY = 'items_search_capabilities_v3';

    /**
     * @var array{products: bool, categories: bool, barcodes: bool, fulltext: bool, min_token: int}|null
     */
    private static ?array $resolved = null;

    public static function productTable(): string
    {
        return config('items.table_prefix').self::PRODUCT_TABLE;
    }

    public static function hasProductIndex(): bool
    {
        return self::resolve()['products'];
    }

    public static function hasCategoryIndex(): bool
    {
        return self::resolve()['categories'];
    }

    /**
     * جدول الباركودات الاضافية. فهرس البحث يُبنى في ترحيل اقدم منه.
     */
    public static function hasSecondaryBarcodes(): bool
    {
        return self::resolve()['barcodes'];
    }

    public static function hasFullText(): bool
    {
        return self::resolve()['fulltext'];
    }

    /**
     * اقصر كلمة يفهرسها FULLTEXT (innodb_ft_min_token_size)، وما دونها يُبحث بـ LIKE.
     */
    public static function minTokenSize(): int
    {
        return self::resolve()['min_token'];
    }

    public static function forget(): void
    {
        self::$resolved = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * البحث بـ LIKE بدل FULLTEXT. للاختبارات داخل معاملة (transaction)، لان
     * InnoDB لا يرى في فهرس FULLTEXT ما لم يُعتمد (commit) بعد.
     */
    public static function withoutFullText(): void
    {
        self::$resolved = ['fulltext' => false] + self::resolve();
    }

    /**
     * @return array{products: bool, categories: bool, barcodes: bool, fulltext: bool, min_token: int}
     */
    private static function resolve(): array
    {
        return self::$resolved ??= Cache::remember(self::CACHE_KEY, now()->addDay(), function (): array {
            try {
                $products = Schema::hasTable(self::productTable());
                $categories = Schema::hasColumn(config('items.table_prefix').'categories', self::CATEGORY_COLUMN);
                $barcodes = Schema::hasTable(config('items.table_prefix').'product_barcodes');
            } catch (Throwable) {
                return ['products' => false, 'categories' => false, 'barcodes' => false, 'fulltext' => false, 'min_token' => 3];
            }

            $fullText = false;
            $minToken = 3;

            if ($products && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                try {
                    $fullText = DB::table('information_schema.statistics')
                        ->whereRaw('table_schema = DATABASE()')
                        ->where('table_name', DB::getTablePrefix().self::productTable())
                        ->where('index_name', self::FULLTEXT_INDEX)
                        ->exists();

                    $minToken = max(1, (int) (DB::selectOne('SELECT @@innodb_ft_min_token_size AS size')->size ?? 3));
                } catch (Throwable) {
                    $fullText = false;
                }
            }

            return ['products' => $products, 'categories' => $categories, 'barcodes' => $barcodes, 'fulltext' => $fullText, 'min_token' => $minToken];
        });
    }
}
