<?php

namespace Modules\Core\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Entities\Concerns\HasSearchIndex;

/**
 * قصر استعلام على ما يطابق نص البحث في اعمدة معيّنة: عمود، او relation.column.
 *
 * اعمدة الجدول نفسه تُبحث في فهرسه اذا كان له فهرس يغطيها (HasSearchIndex)، واعمدة
 * السجل المرتبط في فهرس ذلك السجل (اسم الزبون في فهرس المستخدمين)، والباقي (او كل
 * شيء حين لا يعمل المحرك، او تزيد النتائج على الحد) بـ LIKE في قاعدة البيانات.
 * والمطابقات تُجمع بـ OR كما في البحث بقاعدة البيانات.
 */
final class SearchConstraint
{
    /**
     * @param  list<string>  $columns
     * @param  string|null  $translated  عمود في جدول الترجمات (translations) يُبحث فيه ايضاً
     * @param  bool  $orderByRelevance  ترتيب السجلات بالاقرب (حين يغطي الفهرس الجدول نفسه)
     */
    public static function apply(Builder $query, string $search, array $columns, ?string $translated = null, bool $orderByRelevance = false): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $model = $query->getModel();
        [$own, $relations] = self::split($columns);
        $ownIds = $own !== [] && self::indexCovers($model, $own) ? SearchIndex::matching($model::class, $search) : null;
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $query) use ($model, $own, $relations, $ownIds, $translated, $search, $like): void {
            if ($ownIds !== null) {
                $query->orWhereIntegerInRaw($model->getQualifiedKeyName(), $ownIds);
            } else {
                // كما كُتب (بلا اسم الجدول)، فيصلح لعمود من جدول مربوط (join) في استعلام القائمة.
                foreach ($own as $column) {
                    $query->orWhereLike($column, $like);
                }
            }

            foreach ($relations as $relation => $fields) {
                // علاقة متداخلة (a.b.column) تُبحث في قاعدة البيانات.
                $related = str_contains($relation, '.') ? null : $model->{$relation}()->getRelated();
                $ids = $related !== null && self::indexCovers($related, $fields) ? SearchIndex::matching($related::class, $search) : null;

                $query->orWhereHas($relation, function (Builder $query) use ($ids, $fields, $like): void {
                    if ($ids !== null) {
                        $query->whereIntegerInRaw($query->getModel()->getQualifiedKeyName(), $ids);

                        return;
                    }

                    $query->where(function (Builder $query) use ($fields, $like): void {
                        foreach ($fields as $field) {
                            $query->orWhereLike($query->qualifyColumn($field), $like);
                        }
                    });
                });
            }

            if ($translated !== null && $ownIds === null) {
                $query->orWhereHas('translations', fn (Builder $query) => $query->whereLike($translated, $like));
            }
        });

        if ($ownIds !== null && $orderByRelevance) {
            SearchIndex::orderBy($query, $ownIds);
        }
    }

    /**
     * اعمدة الجدول نفسه، واعمدة كل علاقة.
     *
     * @param  list<string>  $columns
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private static function split(array $columns): array
    {
        $own = [];
        $relations = [];

        foreach ($columns as $column) {
            if (str_contains($column, '.')) {
                $relations[Str::beforeLast($column, '.')][] = Str::afterLast($column, '.');
            } else {
                $own[] = $column;
            }
        }

        return [$own, $relations];
    }

    /**
     * هل في فهرس الجدول كل هذه الاعمدة؟ (والا فالبحث في الفهرس ينقص عن البحث في قاعدة البيانات).
     *
     * @param  list<string>  $columns
     */
    private static function indexCovers(Model $model, array $columns): bool
    {
        return in_array(HasSearchIndex::class, class_uses_recursive($model), true)
            && array_diff($columns, $model::searchColumns()) === [];
    }
}
