<?php

namespace Modules\Core\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The data behind every admin table: filters, sort and page from the URL, one paginated shape for
 * the DataTable component. Rows are mapped to explicit arrays, so a model never leaks a column.
 */
class AdminTable
{
    public const PAGE_SIZES = [10, 25, 50, 100];

    /** @var array<int, AllowedFilter|string> */
    private array $filters = [];

    /** @var array<int, AllowedSort|string> */
    private array $sorts = [];

    private string $defaultSort = '-id';

    /** The relation behind the «Created By» column, or null when the table has none. */
    private ?string $creator = null;

    /** The column the rows are dragged by, or null when the table cannot be reordered. */
    private ?string $reorderColumn = null;

    private function __construct(private readonly Builder $query) {}

    public static function for(Builder $query): self
    {
        return new self($query);
    }

    public function filters(AllowedFilter|string ...$filters): self
    {
        $this->filters = [...$this->filters, ...$filters];

        return $this;
    }

    public function sorts(AllowedSort|string ...$sorts): self
    {
        $this->sorts = $sorts;

        return $this;
    }

    public function defaultSort(string $sort): self
    {
        $this->defaultSort = $sort;

        return $this;
    }

    /**
     * Adds `created_by` (UserCellData) to every row for the shared «Created By» column
     * (`createdByColumn()` in `@/lib/tableColumns`), with the creator and its avatar eager-loaded.
     */
    public function withCreator(string $relation = 'createdBy'): self
    {
        $this->creator = $relation;
        $this->query->with($relation.'.media');

        return $this;
    }

    /**
     * Rows ordered by hand (drag and drop): sorted by `$column` by default, ties by id. The model uses
     * `Modules\Core\Entities\Concerns\HasSortOrder` and its controller `ReordersRecords`.
     */
    public function reorderable(string $column = 'sort_order'): self
    {
        $this->reorderColumn = $column;
        $this->defaultSort = $column;

        return $this;
    }

    /**
     * A free text search on the given translated column (and plain columns), case-insensitive.
     *
     * @param  list<string>  $columns
     */
    public function search(array $columns = [], ?string $translated = 'name'): self
    {
        $this->filters[] = AllowedFilter::callback('search', function (Builder $query, mixed $value) use ($columns, $translated): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->where(function (Builder $query) use ($columns, $translated, $term): void {
                foreach ($columns as $column) {
                    $query->orWhereLike($column, $term);
                }

                if ($translated !== null) {
                    $query->orWhereHas('translations', fn (Builder $query) => $query->whereLike($translated, $term));
                }
            });
        });

        return $this;
    }

    /**
     * @param  Closure(mixed): array<string, mixed>  $row
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>, query: array<string, mixed>}
     */
    public function rows(Closure $row, ?Request $request = null): array
    {
        $request ??= request();

        $perPage = (int) $request->integer('perPage', self::PAGE_SIZES[1]);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : self::PAGE_SIZES[1];

        $sorts = $this->sorts;

        if ($this->reorderColumn !== null && ! in_array($this->reorderColumn, $sorts, true)) {
            $sorts[] = $this->reorderColumn;
        }

        $builder = QueryBuilder::for($this->query, $request)
            ->allowedFilters(...$this->filters)
            ->allowedSorts(...$sorts)
            ->defaultSort($this->defaultSort);

        if ($this->reorderColumn !== null) {
            // Equal positions (new rows, old data) still keep one stable order to drag.
            $builder->orderBy($this->query->getModel()->getQualifiedKeyName());
        }

        $paginator = $builder->paginate($perPage)->withQueryString();

        $map = $this->creator === null ? $row : fn (mixed $model): array => [
            ...$row($model),
            'created_by' => UserCellData::of($model->{$this->creator}),
        ];

        return [
            'reorder' => $this->reorderColumn,
            'data' => $paginator->getCollection()->map($map)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'query' => [
                'filter' => (object) array_filter((array) $request->input('filter', []), fn ($value) => $value !== null && $value !== ''),
                'sort' => $request->input('sort', $this->defaultSort),
            ],
        ];
    }
}
