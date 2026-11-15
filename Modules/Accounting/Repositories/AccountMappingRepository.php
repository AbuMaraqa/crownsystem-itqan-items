<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Repositories\Contracts\AccountMappingRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class AccountMappingRepository extends BaseEloquentRepository implements AccountMappingRepositoryInterface
{
    public function __construct(AccountMapping $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with('account.translations');
    }

    public function sourceModules(): array
    {
        return $this->query()
            ->distinct()
            ->orderBy('source_module')
            ->pluck('source_module')
            ->filter()
            ->values()
            ->all();
    }
}
