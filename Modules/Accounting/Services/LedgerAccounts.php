<?php

namespace Modules\Accounting\Services;

use DomainException;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;

/**
 * حسابات الاستاذ العام المربوطة بمفتاح (accounting_account_mappings، module = Accounting)
 * مع رقم حساب افتراضي من الشجرة. الربط يُنشأ عند اول استخدام ويُعدَّل من «ربط الحسابات».
 */
class LedgerAccounts
{
    public const SOURCE_MODULE = 'Accounting';

    public const PURCHASES_KEY = 'purchase_invoice_purchases';

    public const PURCHASE_TAX_KEY = 'purchase_invoice_tax';

    public const EXCHANGE_GAIN_KEY = 'exchange_gain';

    public const EXCHANGE_LOSS_KEY = 'exchange_loss';

    public const RETAINED_EARNINGS_KEY = 'retained_earnings';

    private const DEFAULT_ACCOUNT_CODES = [
        self::PURCHASES_KEY => '5110',
        self::PURCHASE_TAX_KEY => '1180',
        self::EXCHANGE_GAIN_KEY => '4330',
        self::EXCHANGE_LOSS_KEY => '5520',
        self::RETAINED_EARNINGS_KEY => '3200',
    ];

    public function mapped(string $sourceKey): int
    {
        $defaultAccountId = Account::query()
            ->where('code', self::DEFAULT_ACCOUNT_CODES[$sourceKey] ?? null)
            ->value('id');

        $mapping = AccountMapping::query()->firstOrCreate([
            'source_module' => self::SOURCE_MODULE,
            'source_key' => $sourceKey,
        ], [
            'account_id' => $defaultAccountId,
            'created_by' => auth()->id(),
        ]);

        if (! $mapping->account_id && $defaultAccountId) {
            $mapping->update(['account_id' => $defaultAccountId]);
        }

        $mapping->load('account');

        if (! $mapping->account_id) {
            throw new DomainException(__('Account mapping is missing for :key', ['key' => $sourceKey]));
        }

        if (! $mapping->account?->is_postable) {
            throw new DomainException(__('Mapped account must be postable for :key', ['key' => $sourceKey]));
        }

        return (int) $mapping->account_id;
    }
}
