<?php

namespace Modules\Accounting\Services;

use DomainException;
use Modules\Core\Entities\User;

/**
 * حساب الطرف (عميل/مورد) في دفتر الاستاذ المساعد — مصدر واحد لكل المستندات.
 *
 * لكل عميل حساب فرعي تحت «الذمم المدينة 1130»، ولكل مورد حساب فرعي تحت «الذمم الدائنة 2110»
 * (يُنشأ عند اول قيد ان لم يوجد). الحسابان الأبوان حسابا مجموعة لا يُرحَّل عليهما مباشرة،
 * فرصيد كل طرف وكشف حسابه واعمار ديونه تُقرأ من حسابه وحده.
 */
class PartyAccounts
{
    public const CUSTOMER_PARENT_KEY = 'customer_account_parent';

    public const SUPPLIER_PARENT_KEY = 'supplier_account_parent';

    public function customerAccountId(?int $clientId): int
    {
        return $this->accountId($clientId, self::CUSTOMER_PARENT_KEY)
            ?? throw new DomainException(__('The customer has no active ledger account.'));
    }

    public function supplierAccountId(?int $supplierId): int
    {
        return $this->accountId($supplierId, self::SUPPLIER_PARENT_KEY)
            ?? throw new DomainException(__('The supplier has no active ledger account.'));
    }

    /**
     * حساب الطرف ان وُجد او أمكن انشاؤه، والا null (للحالات التي لها بديل كالمصروفات المستحقة).
     */
    public function accountId(?int $userId, string $parentKey): ?int
    {
        if (! $userId || ! $user = User::query()->find($userId)) {
            return null;
        }

        $account = app(AccountService::class)->createForUser($user, $parentKey);

        return $account && $account->is_postable && $account->is_active ? (int) $account->id : null;
    }
}
