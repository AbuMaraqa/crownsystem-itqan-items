<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface JournalEntryRepositoryInterface extends RepositoryInterface
{
    /**
     * The journal entries table: debit and credit totals in the base currency, reversal count.
     */
    public function tableQuery(): Builder;

    /**
     * An entry with its lines (account, branch, currency) and who created and posted it.
     */
    public function findWithLines(int $id): JournalEntry;
}
