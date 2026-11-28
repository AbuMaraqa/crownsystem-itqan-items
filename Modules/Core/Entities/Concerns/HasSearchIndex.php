<?php

namespace Modules\Core\Entities\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Modules\Core\Support\Search\SearchIndex;

/**
 * جدول له فهرس في محرك البحث (Scout + Meilisearch).
 *
 * الموديل يذكر اعمدة بحثه في searchColumns() (عمود، او relation.column)، وهي نفسها
 * اعمدة البحث في قاعدة البيانات حين لا يعمل المحرك (AdminTable::search())، فلا تُكتب
 * مرتين. كل عمود يُفهرس موحَّداً (SearchIndex::text) ليطابق نص البحث الموحَّد.
 *
 * يُسجَّل الموديل في مزوّد موديوله: SearchIndex::register(Model::class).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasSearchIndex
{
    use Searchable;

    /**
     * الاعمدة التي يُبحث فيها، مرتبةً من الاهم (ترتيبها ترتيب الاهمية في الفهرس).
     *
     * @return list<string>
     */
    abstract public static function searchColumns(): array;

    /**
     * اعمدة الرموز (الارقام المرجعية، الهاتف، البريد): تُطابَق كما كُتبت بلا تصحيح املائي،
     * فلا يظهر PO-0012 لمن كتب PO-0013.
     *
     * @return list<string>
     */
    public static function searchCodeColumns(): array
    {
        $codes = ['reference_no', 'entry_number', 'cheque_number', 'supplier_reference', 'order_number', 'code', 'sku', 'barcode', 'phone', 'email'];

        return array_values(array_filter(static::searchColumns(), fn (string $column): bool => in_array(Str::afterLast($column, '.'), $codes, true)));
    }

    /**
     * حقول للتصفية داخل الفهرس (غير نص البحث)، تضاف الى المستند.
     *
     * @return array<string, scalar|list<scalar>|null>
     */
    public function searchFilterValues(): array
    {
        return [];
    }

    public function toSearchableArray(): array
    {
        $document = ['id' => (int) $this->getKey()];

        foreach (static::searchColumns() as $column) {
            $document[self::searchField($column)] = SearchIndex::text($this->searchValue($column));
        }

        return $document + $this->searchFilterValues();
    }

    /**
     * قيمة عمود البحث: عمود السجل، او عمود السجل المرتبط (او سجلاته، كالترجمات).
     */
    private function searchValue(string $column): mixed
    {
        if (! str_contains($column, '.')) {
            return $this->getAttribute($column);
        }

        $related = data_get($this, Str::beforeLast($column, '.'));
        $field = Str::afterLast($column, '.');

        return $related instanceof Collection ? $related->pluck($field)->all() : data_get($related, $field);
    }

    /**
     * اعدادات فهرس الجدول في Meilisearch (searchIndexSettings يقرؤها SearchIndex::settings()).
     *
     * @return array<string, mixed>
     */
    public function searchIndexSettings(): array
    {
        $fields = array_map(self::searchField(...), static::searchColumns());

        return [
            'searchableAttributes' => $fields,
            'filterableAttributes' => array_keys($this->searchFilterValues()),
            'typoTolerance' => [
                'disableOnAttributes' => array_map(self::searchField(...), static::searchCodeColumns()),
                'disableOnNumbers' => true,
            ],
            'pagination' => ['maxTotalHits' => SearchIndex::MAX_HITS + 1],
        ];
    }

    /**
     * تحميل العلاقات المذكورة في اعمدة البحث مرة لكل دفعة، لا لكل سجل.
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        $relations = self::searchRelations();

        return $relations === [] ? $models : $models->loadMissing($relations);
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        $relations = self::searchRelations();

        return $relations === [] ? $query : $query->with($relations);
    }

    /**
     * @return list<string>
     */
    private static function searchRelations(): array
    {
        return array_values(array_unique(array_map(
            fn (string $column): string => Str::beforeLast($column, '.'),
            array_filter(static::searchColumns(), fn (string $column): bool => str_contains($column, '.'))
        )));
    }

    private static function searchField(string $column): string
    {
        return str_replace('.', '_', $column);
    }
}
