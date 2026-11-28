<?php

namespace Modules\Core\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Support\Search\SearchConstraint;
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

    /** @var list<string> Columns that order rows sharing one position, before the id. */
    private array $reorderTies = [];

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
     * Rows ordered by hand (drag and drop): sorted by `$column` by default, ties by `$thenBy` (e.g. a
     * category code, so rows never dragged keep the catalog structure) and finally by id. The model uses
     * `Modules\Core\Entities\Concerns\HasSortOrder` and its controller `ReordersRecords`.
     *
     * @param  list<string>  $thenBy
     */
    public function reorderable(string $column = 'sort_order', array $thenBy = []): self
    {
        $this->reorderColumn = $column;
        $this->reorderTies = $thenBy;
        $this->defaultSort = $column;

        return $this;
    }

    /**
     * A free text search on the given translated column (and plain columns), case-insensitive.
     * A column on a related model is written `relation.column` (e.g. `user.name` for the customer).
     *
     * A model with a search index (`HasSearchIndex`) is searched in the search engine (Meilisearch:
     * typo tolerant, Arabic letters unified, best match first unless the user picked a sort) on its
     * `searchColumns()`, plus the given columns; a related model with its own index (e.g. `client.name`)
     * is searched in its index. When the engine is off or down, the same columns are searched in the
     * database (`SearchConstraint`).
     *
     * @param  list<string>  $columns
     */
    public function search(array $columns = [], ?string $translated = 'name'): self
    {
        $model = $this->query->getModel();

        if (in_array(HasSearchIndex::class, class_uses_recursive($model), true)) {
            $columns = array_values(array_unique([...$model::searchColumns(), ...$columns]));
            $translated = null;
        }

        $this->filters[] = AdminFilter::typed(AllowedFilter::callback('search', function (Builder $query, mixed $value) use ($columns, $translated): void {
            SearchConstraint::apply($query, (string) $value, $columns, $translated, orderByRelevance: ! request()->filled('sort'));
        }), AdminFilter::TEXT);

        return $this;
    }

    /**
     * Wrong input in the link (an unknown filter, a bad date, a sort or page size the table does not have)
     * never breaks the page: it falls back to the default and its message goes out in `warnings`.
     *
     * @param  Closure(mixed): array<string, mixed>  $row
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>, query: array<string, mixed>, warnings: object, reorder: string|null}
     */
    public function rows(Closure $row, ?Request $request = null): array
    {
        $request ??= request();

        $sorts = $this->sorts;

        if ($this->reorderColumn !== null && ! in_array($this->reorderColumn, $sorts, true)) {
            $sorts[] = $this->reorderColumn;
        }

        $sortNames = array_map(fn (AllowedSort|string $sort): string => $sort instanceof AllowedSort ? $sort->getName() : ltrim($sort, '-'), $sorts);
        $input = TableInput::read($request, $this->filters, $sortNames, $this->defaultSort, self::PAGE_SIZES);

        $builder = QueryBuilder::for($this->query, $input->request($request))
            ->allowedFilters(...$this->filters)
            ->allowedSorts(...$sorts)
            ->defaultSort($this->defaultSort);

        if ($this->reorderColumn !== null) {
            // Equal positions (new rows, old data) still keep one stable order to drag.
            foreach ($this->reorderTies as $column) {
                $builder->orderBy($this->query->qualifyColumn($column));
            }

            $builder->orderBy($this->query->getModel()->getQualifiedKeyName());
        }

        $paginator = $builder->paginate($input->perPage, ['*'], 'page', $input->page);

        // Past the last page (often after deleting its last rows): show the last page instead of nothing.
        if ($paginator->isEmpty() && $input->page > 1 && $paginator->total() > 0) {
            $paginator = $builder->paginate($input->perPage, ['*'], 'page', $paginator->lastPage());
        }

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
                'filter' => (object) $input->filter,
                'sort' => $input->sort,
            ],
            'warnings' => (object) $input->warnings,
        ];
    }
}
