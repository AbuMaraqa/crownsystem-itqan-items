<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Repositories\Contracts\ChequeRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ChequeRepository extends BaseEloquentRepository implements ChequeRepositoryInterface
{
    public function __construct(Cheque $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with([
            'client.media',
            'branch.translations',
            'account.translations',
            'clearingAccount.translations',
            'currency',
        ]);
    }

    public function findWithTrashedOrFail(int $id): Cheque
    {
        /** @var Cheque */
        return Cheque::withTrashed()->findOrFail($id);
    }
}
