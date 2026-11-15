<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Role;

/**
 * انشاء طرف جديد من داخل القائمة المنسدلة (CreatableSelect + QuickCreateModal في Vue)
 * بدل الخروج من المستند، ويعيد معرّفه ليُختار في الحقل مباشرة.
 */
class QuickCreate
{
    /**
     * انشاء الطرف بالدور المطلوب، وارجاع معرّفه ليختاره الحقل فوراً.
     *
     * @param  array<string, mixed>  $data
     */
    public static function party(array $data, string $role): int
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'phone' => $data['phone'] ?? null,
            // لا احد يسجّل دخول بهذا الحساب، لكن العمود مطلوب.
            'password' => Hash::make(Str::random(40)),
        ]);

        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        // مورّد او عميل بلا حساب في شجرة الحسابات يكسر القيود المحاسبية لاحقاً،
        // وهي نفس الخطوة التي تنفّذها صفحة انشاء المورّد الكاملة.
        app(AccountService::class)->syncForRoles($user, [$role]);

        return (int) $user->id;
    }
}
