<?php

namespace Modules\Core\Support\Search;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * المدخل الوحيد الى محرك البحث (Laravel Scout + Meilisearch) في النظام كله.
 *
 * المحرك يعطي معرّفات السجلات مرتبةً بالاقرب (مع تحمّل الاخطاء الاملائية)، وقاعدة
 * البيانات تطبّق عليها باقي الشروط (الفرع، الصلاحية، الفلاتر)، فلا تنتقل قواعد العمل
 * الى الفهرس.
 *
 * المحرك ليس جزءاً من العملية: اذا توقف خادم Meilisearch يُسجَّل الخطأ (report) مرة،
 * ويُعدّ المحرك متوقفاً دقيقة، فيعود البحث الى قاعدة البيانات (ids() تعيد null)
 * ويكمل الحفظ، ويُعلَّم فهرس الجدول «قديماً» ليعيد بناءه الامر search:sync --stale.
 */
final class SearchIndex
{
    /**
     * اكثر عدد من النتائج يُطلب من المحرك لجدول في لوحة التحكم، وهو حد الفهرس
     * (pagination.maxTotalHits). ما زاد عليه يُبحث فيه بقاعدة البيانات.
     */
    public const MAX_HITS = 10000;

    /**
     * اكثر عدد من النتائج يُرتَّب بالاقرب داخل استعلام الجدول (CASE ... END).
     */
    private const RELEVANCE_ORDER_LIMIT = 500;

    /**
     * مدة اعتبار المحرك متوقفاً بعد خطأ، بالثواني.
     */
    private const DOWN_SECONDS = 60;

    private const DOWN_KEY = 'search_index.down';

    private const STALE_KEY = 'search_index.stale';

    /**
     * @var list<class-string<Model>>
     */
    private static array $models = [];

    /**
     * تسجيل الجداول المفهرسة (من مزوّد كل موديول). اسماء الاصناف فقط، فلا تُحمَّل الموديلات.
     *
     * @param  class-string<Model>  ...$models
     */
    public static function register(string ...$models): void
    {
        self::$models = array_values(array_unique([...self::$models, ...$models]));
    }

    /**
     * @return list<class-string<Model>>
     */
    public static function models(): array
    {
        return self::$models;
    }

    /**
     * اعدادات فهارس Meilisearch مفهرسةً باسم الفهرس، من الموديلات نفسها.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function settings(): array
    {
        $settings = [];

        foreach (self::$models as $class) {
            $model = new $class;
            $indexSettings = method_exists($model, 'searchIndexSettings') ? $model->searchIndexSettings() : [];

            if (config('scout.soft_delete') && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $indexSettings['filterableAttributes'] = array_values(array_unique([...($indexSettings['filterableAttributes'] ?? []), '__soft_deleted']));
            }

            $settings[$model->searchableAs()] = $indexSettings;
        }

        return $settings;
    }

    /**
     * هل يعمل المحرك الآن؟ (مضبوط في الاعدادات، وغير متوقف بعد خطأ قريب).
     */
    public static function enabled(): bool
    {
        return ! in_array(config('scout.driver'), [null, '', 'null'], true) && ! Cache::has(self::DOWN_KEY);
    }

    /**
     * هل للجدول فهرس في المحرك ويعمل المحرك الآن؟
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function covers(Model|string $model): bool
    {
        return self::enabled() && in_array(Searchable::class, class_uses_recursive($model), true);
    }

    /**
     * معرّفات السجلات المطابقة مرتبةً بالاقرب، او null اذا لم يكن المحرك متاحاً (فيبحث
     * المستدعي بقاعدة البيانات). ان لم يطابق شيء جُرّب النص بعد تصحيح لغة لوحة المفاتيح.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, scalar|list<scalar>>  $filters  حقول قابلة للتصفية في الفهرس => قيمة او قائمة قيم
     * @param  array<string, mixed>  $options  خيارات Meilisearch (matchingStrategy ...)
     * @param  bool  $swapLayout  تجربة النص بلغة لوحة المفاتيح الاخرى ان لم يطابق شيء
     * @return list<int>|null
     */
    public static function ids(string $model, ?string $search, int $limit = self::MAX_HITS, array $filters = [], array $options = [], bool $swapLayout = true): ?array
    {
        if (! self::covers($model)) {
            return null;
        }

        $text = self::queryText($search);

        // نص بلا حروف ولا ارقام (رموز فقط): لا كلمة يُبحث بها في الفهرس، فقاعدة البيانات.
        if ($text === '') {
            return null;
        }

        try {
            $ids = self::keys($model, $text, $limit, $filters, $options);

            if ($ids === [] && $swapLayout && ($swapped = KeyboardLayout::swap((string) $search)) !== null && ($swappedText = self::queryText($swapped)) !== '') {
                $ids = self::keys($model, $swappedText, $limit, $filters, $options);
            }

            return $ids;
        } catch (Throwable $exception) {
            self::failed($exception);

            return null;
        }
    }

    /**
     * كل السجلات المطابقة مرتبةً بالاقرب (حتى MAX_HITS)، او null اذا لم يكن المحرك متاحاً او
     * زادت النتائج على الحد فلا تكتمل، فيبحث المستدعي بقاعدة البيانات.
     *
     * @param  class-string<Model>  $model
     * @return list<int>|null
     */
    public static function matching(string $model, ?string $search): ?array
    {
        $ids = self::ids($model, $search, self::MAX_HITS + 1);

        return $ids === null || count($ids) > self::MAX_HITS ? null : $ids;
    }

    /**
     * قصر استعلام على السجلات المطابقة، مرتبةً بالاقرب اذا طُلب. false اذا لم يكن المحرك
     * متاحاً او زادت النتائج على الحد، فيبحث المستدعي بقاعدة البيانات.
     */
    public static function constrain(Builder $query, ?string $search, bool $orderByRelevance = false): bool
    {
        $ids = self::matching($query->getModel()::class, $search);

        if ($ids === null) {
            return false;
        }

        $query->whereIntegerInRaw($query->qualifyColumn($query->getModel()->getKeyName()), $ids);

        if ($orderByRelevance) {
            self::orderBy($query, $ids);
        }

        return true;
    }

    /**
     * ترتيب الاستعلام بترتيب المعرّفات (الاقرب اولاً). ما بعد الحد يأتي بعدها بترتيب الجدول.
     *
     * @param  list<int>  $ids
     */
    public static function orderBy(Builder $query, array $ids): void
    {
        $ids = array_slice($ids, 0, self::RELEVANCE_ORDER_LIMIT);

        if ($ids === []) {
            return;
        }

        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($query->getModel()->getKeyName()));
        $cases = '';

        foreach ($ids as $position => $id) {
            $cases .= ' WHEN '.(int) $id.' THEN '.$position;
        }

        $query->orderByRaw("CASE {$column}{$cases} ELSE ".count($ids).' END');
    }

    /**
     * نص الفهرس موحَّداً كما يُكتب في المستند (الهمزات، التاء المربوطة، التشكيل، الارقام
     * الهندية، حالة الحروف)، مع الرمز ملتصقاً اذا كان فيه فواصل (PO-0001 ← po0001).
     */
    public static function text(mixed ...$values): string
    {
        $words = [];

        foreach (self::flatten($values) as $value) {
            $normalized = TextNormalizer::normalize($value);

            if ($normalized === '') {
                continue;
            }

            $words[$normalized] = true;
            $compact = str_replace(' ', '', $normalized);

            // رمز بلا مسافات فيه فواصل (PO-0012، 0599-123): يُضاف ملتصقاً، لا الاسماء.
            if ($compact !== $normalized && ! preg_match('/\s/u', trim($value))) {
                $words[$compact] = true;
            }
        }

        return implode(' ', array_keys($words));
    }

    /**
     * نص البحث موحَّداً كنص الفهرس.
     */
    public static function queryText(?string $search): string
    {
        return TextNormalizer::normalize(mb_substr(trim((string) $search), 0, 200));
    }

    /**
     * كتابة مستندات جاهزة في فهرس جدول دون تحميل الموديلات (لبناء فهرس كبير دفعة دفعة).
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $documents  لكل مستند حقل id
     */
    public static function put(string $model, array $documents): void
    {
        if ($documents === [] || ! self::covers($model)) {
            if ($documents !== [] && self::configured()) {
                self::markStale($model);
            }

            return;
        }

        try {
            $engine = self::engine();

            if ($engine instanceof MeilisearchEngine) {
                $engine->index((new $model)->indexableAs())->addDocuments($documents, 'id');
            }
        } catch (Throwable $exception) {
            self::failed($exception, $model);
        }
    }

    /**
     * حذف مستندات من فهرس جدول بمعرّفاتها.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     */
    public static function forget(string $model, array $ids): void
    {
        if ($ids === [] || ! self::covers($model)) {
            if ($ids !== [] && self::configured()) {
                self::markStale($model);
            }

            return;
        }

        try {
            $engine = self::engine();

            if ($engine instanceof MeilisearchEngine) {
                $engine->index((new $model)->indexableAs())->deleteDocuments(array_values($ids));
            }
        } catch (Throwable $exception) {
            self::failed($exception, $model);
        }
    }

    /**
     * خطأ من المحرك: يُسجَّل مرة في كل فترة توقف، ويُعدّ المحرك متوقفاً، ويُعلَّم الفهرس قديماً.
     *
     * @param  class-string<Model>|null  $model  الجدول الذي لم يُحدَّث فهرسه
     */
    public static function failed(Throwable $exception, ?string $model = null): void
    {
        if (Cache::add(self::DOWN_KEY, true, self::DOWN_SECONDS)) {
            report($exception);
        }

        if ($model !== null) {
            self::markStale($model);
        }
    }

    /**
     * جدول فاته تحديث في الفهرس (المحرك متوقف)، فيعيد search:sync --stale بناءه.
     *
     * @param  class-string<Model>  $model
     */
    public static function markStale(string $model): void
    {
        $stale = self::stale();

        if (! in_array($model, $stale, true)) {
            Cache::forever(self::STALE_KEY, [...$stale, $model]);
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    public static function stale(): array
    {
        return array_values(Cache::get(self::STALE_KEY, []));
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function clearStale(string $model): void
    {
        Cache::forever(self::STALE_KEY, array_values(array_diff(self::stale(), [$model])));
    }

    /**
     * اعادة المحاولة فوراً بدل انتظار انتهاء فترة التوقف (بعد تشغيل الخادم).
     */
    public static function reset(): void
    {
        Cache::forget(self::DOWN_KEY);
    }

    /**
     * هل يجيب خادم المحرك الآن؟ (لأوامر artisan، لا لكل طلب).
     */
    public static function healthy(): bool
    {
        try {
            $engine = self::engine();

            return $engine instanceof MeilisearchEngine ? $engine->isHealthy() : self::configured();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * هل اختير محرك في الاعدادات (ولو كان متوقفاً الآن)؟
     */
    public static function configured(): bool
    {
        return ! in_array(config('scout.driver'), [null, '', 'null'], true);
    }

    public static function engine(): mixed
    {
        return app(EngineManager::class)->engine();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, scalar|list<scalar>>  $filters
     * @param  array<string, mixed>  $options
     * @return list<int>
     */
    private static function keys(string $model, string $text, int $limit, array $filters, array $options): array
    {
        $builder = $model::search($text)->take(max($limit, 1));

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $builder->withTrashed();
        }

        // محرك collection (الاختبارات) يطبّق الشروط على اعمدة الجدول، وقاعدة البيانات
        // تعيد التصفية على كل حال؛ فالشروط والخيارات لـ Meilisearch وحده.
        if (self::engine() instanceof MeilisearchEngine) {
            foreach ($filters as $field => $value) {
                is_array($value) ? $builder->whereIn($field, $value) : $builder->where($field, $value);
            }

            // المعرّف وحده يكفي، فتصغر الاستجابة لآلاف النتائج.
            $builder->options(['attributesToRetrieve' => [], ...$options]);
        }

        return $builder->keys()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private static function flatten(iterable $values): array
    {
        $flat = [];

        foreach ($values as $value) {
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if (is_iterable($value)) {
                $flat = [...$flat, ...self::flatten($value)];
            } elseif (is_scalar($value) && (string) $value !== '') {
                $flat[] = (string) $value;
            }
        }

        return $flat;
    }
}
