<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Repositories\Contracts\JournalEntryRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class JournalEntryRepository extends BaseEloquentRepository implements JournalEntryRepositoryInterface
{
    public function __construct(JournalEntry $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->withSum('lines as debit_total', 'debit_base')
            ->withSum('lines as credit_total', 'credit_base')
            ->withCount('reversals');
    }

    public function findWithLines(int $id): JournalEntry
    {
        /** @var JournalEntry */
        return $this->query()
            ->with([
                'lines' => fn ($lines) => $lines->orderBy('id'),
                'lines.account.translations',
                'lines.branch.translations',
                'lines.currency',
                'createdBy',
                'postedBy',
                'reversalOf',
            ])
            ->withCount('reversals')
            ->findOrFail($id);
    }
}
