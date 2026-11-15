<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Repositories\Contracts\CustomerGroupRepositoryInterface;
use Modules\Core\Entities\User;
use Modules\Core\Support\SafeDelete;

/**
 * تصنيفات العملاء: نسبة ربح تُضاف على سعر التكلفة لعملاء التصنيف، وقائمة اسعار، واعضاء
 * التصنيف. الزبون في تصنيف واحد فقط.
 */
class CustomerGroupService extends LookupService
{
    public function __construct(CustomerGroupRepositoryInterface $groups)
    {
        parent::__construct($groups);
    }

    /**
     * @param  array<string, mixed>  $data  with `customer_ids`
     */
    public function create(array $data, ?int $userId): CustomerGroup
    {
        return DB::transaction(function () use ($data, $userId): CustomerGroup {
            $customerIds = $data['customer_ids'] ?? [];
            unset($data['customer_ids']);

            /** @var CustomerGroup $group */
            $group = parent::create($data, $userId);
            $this->syncCustomers($group, $customerIds);

            return $group;
        });
    }

    /**
     * @param  CustomerGroup  $record
     * @param  array<string, mixed>  $data  with `customer_ids`
     */
    public function update(Model $record, array $data): CustomerGroup
    {
        return DB::transaction(function () use ($record, $data): CustomerGroup {
            $customerIds = $data['customer_ids'] ?? [];
            unset($data['customer_ids']);

            /** @var CustomerGroup $group */
            $group = parent::update($record, $data);
            $this->syncCustomers($group, $customerIds);

            return $group;
        });
    }

    /**
     * الحذف ناعم فلا يفك المفتاح الاجنبي اعضاءه؛ يُفكّون هنا قبل الحذف.
     *
     * @param  CustomerGroup  $record
     */
    public function delete(Model $record): bool
    {
        return SafeDelete::attempt(fn () => DB::transaction(function () use ($record): void {
            $record->customers()->update(['customer_group_id' => null]);
            $this->records->delete($record);
        }));
    }

    /**
     * الزبون في تصنيف واحد: المختارون ينتقلون اليه، ومن أُزيل منه يصبح بلا تصنيف.
     *
     * @param  array<int, mixed>  $customerIds
     */
    private function syncCustomers(CustomerGroup $group, array $customerIds): void
    {
        $customerIds = array_values(array_map('intval', array_filter($customerIds, 'filled')));

        $group->customers()->whereKeyNot($customerIds)->update(['customer_group_id' => null]);

        if ($customerIds !== []) {
            User::query()->whereKey($customerIds)->update(['customer_group_id' => $group->id]);
        }
    }
}
