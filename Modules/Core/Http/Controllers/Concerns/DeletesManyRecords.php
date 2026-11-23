<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Support\BulkDelete;

/**
 * The `<routes>.destroy-many` action of a table (`DataTable :bulk-delete-url`). The route carries
 * `->can('<Entity> Delete')`; the rows are checked and deleted by `Modules\Core\Support\BulkDelete`.
 */
trait DeletesManyRecords
{
    /**
     * The rows the user may delete: the table's own query (the repository's `tableQuery()`).
     *
     * @return Builder<Model>
     */
    abstract protected function bulkDeleteQuery(): Builder;

    /**
     * Deletes one row with the same rules as `destroy()`: `false` or a DomainException keeps it.
     */
    abstract protected function deleteRecord(Model $record): mixed;

    public function destroyMany(Request $request): RedirectResponse
    {
        return BulkDelete::handle($request, $this->bulkDeleteQuery(), fn (Model $record): mixed => $this->deleteRecord($record));
    }
}
