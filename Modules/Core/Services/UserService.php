<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Entities\User;
use Modules\Core\Enums\UserRole;
use Modules\Core\Repositories\Contracts\RoleRepositoryInterface;
use Modules\Core\Repositories\Contracts\UserRepositoryInterface;
use Modules\Core\Support\SafeDelete;

/**
 * اضافة المستخدمين وتعديلهم وحذفهم. كل حفظ يزامن الادوار وحساب المستخدم في دليل الحسابات
 * (مورد تحت الذمم الدائنة، زبون تحت الذمم المدينة) في معاملة واحدة.
 */
class UserService
{
    /** الحقول التي لها معنى للزبون فقط. */
    private const CUSTOMER_FIELDS = ['customer_group_id', 'profit_margin'];

    /** سعر سطور المستندات: للزبون والمورد فقط. */
    private const PARTY_FIELDS = ['use_last_price', 'price_list_id'];

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly AccountService $accounts,
    ) {}

    /**
     * Super Admin لا يديره الا Super Admin: صلاحية «تعديل مستخدم» ليست طريقاً للاستيلاء على النظام.
     */
    public function canManage(User $actor, User $target): bool
    {
        return ! $target->hasRole(UserRole::SUPER_ADMIN->value) || $actor->hasRole(UserRole::SUPER_ADMIN->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        $roleNames = $this->roleNames($data['roles'], $actor);

        $user = DB::transaction(function () use ($data, $roleNames): User {
            /** @var User $user */
            $user = $this->users->create($this->attributes($data, $roleNames));
            $user->syncRoles($roleNames);
            $this->accounts->syncForRoles($user, $roleNames);

            return $user;
        });

        // الصورة ملف على القرص، خارج المعاملة.
        $user->generateAvatar();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $roleNames = $this->roleNames($data['roles'], $actor);

        if (! in_array(UserRole::SUPER_ADMIN->value, $roleNames, true) && $this->isLastSuperAdmin($user)) {
            throw ValidationException::withMessages(['roles' => __('The system must keep at least one Super Admin.')]);
        }

        return DB::transaction(function () use ($user, $data, $roleNames): User {
            /** @var User $user */
            $user = $this->users->update($user, $this->attributes($data, $roleNames));
            $user->syncRoles($roleNames);
            $this->accounts->syncForRoles($user, $roleNames);

            return $user;
        });
    }

    /**
     * @throws DomainException حين يجب ان يبقى المستخدم
     */
    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new DomainException(__('You cannot delete your own account.'));
        }

        if ($this->isLastSuperAdmin($user)) {
            throw new DomainException(__('The system must keep at least one Super Admin.'));
        }

        if (! SafeDelete::attempt(fn () => DB::transaction(fn () => $this->users->delete($user)))) {
            throw new DomainException(SafeDelete::message());
        }
    }

    private function isLastSuperAdmin(User $user): bool
    {
        return $user->hasRole(UserRole::SUPER_ADMIN->value)
            && $this->users->countWithRole(UserRole::SUPER_ADMIN->value) <= 1;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function roleNames(array $ids, User $actor): array
    {
        $names = $this->roles->namesOf($ids);

        if (in_array(UserRole::SUPER_ADMIN->value, $names, true) && ! $actor->hasRole(UserRole::SUPER_ADMIN->value)) {
            throw ValidationException::withMessages(['roles' => __('Only a Super Admin can give the Super Admin role.')]);
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $roleNames
     * @return array<string, mixed>
     */
    private function attributes(array $data, array $roleNames): array
    {
        $isCustomer = in_array(UserRole::CUSTOMER->value, $roleNames, true);
        $isParty = $isCustomer || in_array('Supplier', $roleNames, true);

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
        ];

        // كالنموذج القديم: حقول قسم مخفي (لا يخص الدور) لا تُحفظ، فتبقى قيمها كما هي.
        $sections = [...($isCustomer ? self::CUSTOMER_FIELDS : []), ...($isParty ? self::PARTY_FIELDS : [])];

        foreach ($sections as $field) {
            $attributes[$field] = $data[$field] ?? null;
        }

        // يُشفَّر عبر cast النموذج (hashed). كلمة مرور فارغة عند التعديل تُبقي الحالية.
        if (filled($data['password'] ?? null)) {
            $attributes['password'] = $data['password'];
        }

        return $attributes;
    }
}
