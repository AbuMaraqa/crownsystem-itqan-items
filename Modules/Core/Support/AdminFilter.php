<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * The server side of the table filters in resources/js/types/table.ts. A range filter named `key`
 * arrives as `filter[key_from]` and `filter[key_to]`, so it spreads into two allowed filters:
 * `->filters(AdminFilter::boolean('is_active'), ...AdminFilter::dateRange('created_at'))`.
 */
final class AdminFilter
{
    /** What a filter's value must look like (`TableInput` checks it before the query runs). */
    public const VALUE = 'value';

    public const TEXT = 'text';

    public const BOOLEAN = 'boolean';

    public const ID = 'id';

    public const DATE = 'date';

    public const NUMBER = 'number';

    /** @var \WeakMap<AllowedFilter, string>|null */
    private static ?\WeakMap $types = null;

    /**
     * The kind of value a filter takes. A filter made outside this class is a plain value.
     */
    public static function typeOf(AllowedFilter $filter): string
    {
        return isset(self::$types[$filter]) ? self::$types[$filter] : self::VALUE;
    }

    public static function typed(AllowedFilter $filter, string $type): AllowedFilter
    {
        self::$types ??= new \WeakMap;
        self::$types[$filter] = $type;

        return $filter;
    }

    public static function exact(string $name, ?string $column = null): AllowedFilter
    {
        return self::typed(AllowedFilter::exact($name, $column ?? $name), self::VALUE);
    }

    /**
     * PostgreSQL has real booleans: the URL's 1 and 0 are converted before they reach the query.
     */
    public static function boolean(string $name, ?string $column = null): AllowedFilter
    {
        return self::typed(AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($name, $column): void {
            $query->where($column ?? $name, filter_var($value, FILTER_VALIDATE_BOOLEAN));
        }), self::BOOLEAN);
    }

    /**
     * Case-insensitive text match on a column.
     */
    public static function text(string $name, ?string $column = null): AllowedFilter
    {
        return self::typed(AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($name, $column): void {
            $query->whereLike($column ?? $name, self::likeTerm($value));
        }), self::TEXT);
    }

    /**
     * Case-insensitive match on a translated column.
     */
    public static function translated(string $name, ?string $column = null): AllowedFilter
    {
        return self::typed(AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($name, $column): void {
            $query->whereHas('translations', fn (Builder $query) => $query->whereLike($column ?? $name, self::likeTerm($value)));
        }), self::TEXT);
    }

    /**
     * Rows linked to the chosen id through a relation.
     */
    public static function relation(string $name, string $relation, string $column = 'id'): AllowedFilter
    {
        return self::typed(AllowedFilter::callback($name, function (Builder $query, mixed $value) use ($relation, $column): void {
            $query->whereHas($relation, fn (Builder $query) => $query->where($query->qualifyColumn($column), (int) $value));
        }), self::ID);
    }

    /**
     * @return array{0: AllowedFilter, 1: AllowedFilter}
     */
    public static function dateRange(string $name, ?string $column = null): array
    {
        $column ??= $name;

        return [
            self::typed(AllowedFilter::callback("{$name}_from", function (Builder $query, mixed $value) use ($column): void {
                if (($date = self::date($value)) !== null) {
                    $query->whereDate($column, '>=', $date);
                }
            }), self::DATE),
            self::typed(AllowedFilter::callback("{$name}_to", function (Builder $query, mixed $value) use ($column): void {
                if (($date = self::date($value)) !== null) {
                    $query->whereDate($column, '<=', $date);
                }
            }), self::DATE),
        ];
    }

    /**
     * @return array{0: AllowedFilter, 1: AllowedFilter}
     */
    public static function numberRange(string $name, ?string $column = null): array
    {
        $column ??= $name;

        return [
            self::typed(AllowedFilter::callback("{$name}_from", function (Builder $query, mixed $value) use ($column): void {
                if (is_numeric($value)) {
                    $query->where($column, '>=', $value);
                }
            }), self::NUMBER),
            self::typed(AllowedFilter::callback("{$name}_to", function (Builder $query, mixed $value) use ($column): void {
                if (is_numeric($value)) {
                    $query->where($column, '<=', $value);
                }
            }), self::NUMBER),
        ];
    }

    /**
     * A typed date, or null. PostgreSQL rejects a malformed date with an error, not an empty result.
     */
    public static function date(mixed $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

        return $date && $date->format('Y-m-d') === (string) $value ? (string) $value : null;
    }

    private static function likeTerm(mixed $value): string
    {
        return '%'.addcslashes(trim((string) $value), '%_\\').'%';
    }
}
