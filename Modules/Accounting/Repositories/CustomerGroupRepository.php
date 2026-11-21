<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Repositories\Contracts\CustomerGroupRepositoryInterface;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\BaseEloquentRepository;

class CustomerGroupRepository extends BaseEloquentRepository implements CustomerGroupRepositoryInterface
{
    private const CUSTOMER_OPTIONS_LIMIT = 50;

    public function __construct(CustomerGroup $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['customers' => fn ($query) => $query->select(['id', 'name', 'customer_group_id'])->orderBy('name')])
            ->withCount('customers');
    }

    public function customerOptions(string $term = ''): array
    {
        $term = trim($term);

        return User::query()
            ->customerRole()
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('email', $like));
            })
            ->orderBy('name')
            ->limit(self::CUSTOMER_OPTIONS_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (int) $user->id, 'label' => (string) $user->name])
            ->all();
    }
}
