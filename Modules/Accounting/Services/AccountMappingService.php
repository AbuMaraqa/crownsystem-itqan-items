<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Repositories\Contracts\AccountMappingRepositoryInterface;

/**
 * ربط مفاتيح القيود الآلية (المبيعات، المخزون، الاطراف...) بحسابات دليل الحسابات. الروابط
 * تنشئها الهجرات والبذور؛ المستخدم يغيّر الحساب فقط.
 */
class AccountMappingService
{
    public function __construct(private readonly AccountMappingRepositoryInterface $mappings) {}

    public function setAccount(AccountMapping $mapping, ?int $accountId): AccountMapping
    {
        /** @var AccountMapping */
        return $this->mappings->update($mapping, ['account_id' => $accountId]);
    }
}
