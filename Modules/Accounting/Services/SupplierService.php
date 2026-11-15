<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Repositories\Contracts\SupplierRepositoryInterface;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Role;

/**
 * الموردون مستخدمون بدور Supplier لا يدخلون النظام (كلمة مرور عشوائية، والبريد اختياري).
 * كل حفظ يتأكد من حساب المورد في دليل الحسابات تحت الذمم الدائنة (AccountService::syncForRoles).
 */
class SupplierService
{
    public const ROLE = 'Supplier';

    private const FIELDS = ['name', 'email', 'phone', 'tax_number', 'address'];

    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly AccountService $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        $supplier = DB::transaction(function () use ($data): User {
            /** @var User $supplier */
            $supplier = $this->suppliers->create([
                ...Arr::only($data, self::FIELDS),
                'password' => Hash::make(Str::random(40)),
            ]);

            $supplier->assignRole(Role::firstOrCreate(['name' => self::ROLE, 'guard_name' => 'web']));
            $this->accounts->syncForRoles($supplier, [self::ROLE]);

            return $supplier;
        });

        // الصورة ملف على القرص، خارج المعاملة (كما في UserService).
        $supplier->generateAvatar();

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $supplier, array $data): User
    {
        return DB::transaction(function () use ($supplier, $data): User {
            /** @var User $supplier */
            $supplier = $this->suppliers->update($supplier, Arr::only($data, self::FIELDS));
            $this->accounts->syncForRoles($supplier, $supplier->getRoleNames()->all());

            return $supplier;
        });
    }
}
