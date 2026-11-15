<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

/**
 * حسابات قيود المخزون (جرد مستمر):
 *
 *   سند استلام:   من حـ/ مخزون المستودع          الى حـ/ بضاعة مستلمة لم تصل فواتيرها
 *   فاتورة شراء:  من حـ/ بضاعة مستلمة لم تصل فواتيرها (+ فروقات الاسعار)   الى حـ/ المورد
 *   سند تسليم:    من حـ/ تكلفة البضاعة المباعة    الى حـ/ مخزون المستودع
 *   تحويل:        من حـ/ مخزون المستودع المستلم  الى حـ/ مخزون المستودع المرسل
 *   رصيد افتتاحي: من حـ/ مخزون المستودع          الى حـ/ ارصدة افتتاحية
 *   جرد/تسوية:    العجز من حـ/ عجز المخزون، والزيادة الى حـ/ عجز المخزون
 *   شحن مباشر للزبون (لا يدخل المخزن): فاتورة الشراء من حـ/ تكلفة البضاعة المباعة
 *
 * لكل مخزن حساب فرعي تحت «المخزون 1140» فتظهر قيمة كل مخزن على حدة.
 * الحسابات الناقصة تُنشأ عند اول استخدام، والربط قابل للتعديل من «ربط الحسابات».
 */
class InventoryAccounts
{
    public const SOURCE_MODULE = 'Inventory';

    public const INVENTORY_KEY = 'inventory';

    public const GRNI_KEY = 'goods_received_not_invoiced';

    public const COGS_KEY = 'cost_of_goods_sold';

    public const DIRECT_COST_KEY = 'direct_shipment_cost';

    public const SHRINKAGE_KEY = 'inventory_adjustment';

    public const PRICE_VARIANCE_KEY = 'purchase_price_variance';

    public const OPENING_KEY = 'opening_balance_equity';

    private const DEFAULT_ACCOUNT_CODES = [
        self::INVENTORY_KEY => '1140',
        self::GRNI_KEY => '2125',
        self::COGS_KEY => '5100',
        self::DIRECT_COST_KEY => '5100',
        self::SHRINKAGE_KEY => '5140',
        self::PRICE_VARIANCE_KEY => '5115',
        self::OPENING_KEY => '3700',
    ];

    /**
     * حسابات لا توجد في الشجرة الافتراضية فتُنشأ عند الحاجة.
     */
    private const MISSING_ACCOUNTS = [
        '2125' => ['parent' => '2100', 'type' => AccountType::LIABILITY, 'normal_balance' => NormalBalance::CREDIT, 'en' => 'Goods Received Not Invoiced', 'ar' => 'بضاعة مستلمة لم تصل فواتيرها'],
        '5115' => ['parent' => '5000', 'type' => AccountType::EXPENSE, 'normal_balance' => NormalBalance::DEBIT, 'en' => 'Purchase Price Variance', 'ar' => 'فروقات أسعار المشتريات'],
        '3700' => ['parent' => '3000', 'type' => AccountType::EQUITY, 'normal_balance' => NormalBalance::CREDIT, 'en' => 'Opening Balance Equity', 'ar' => 'أرصدة افتتاحية'],
    ];

    public function mapped(string $sourceKey): int
    {
        $code = self::DEFAULT_ACCOUNT_CODES[$sourceKey] ?? null;
        $defaultAccountId = $code ? $this->accountIdByCode($code) : null;

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

    /**
     * حساب مخزون المستودع؛ يُنشأ فرعياً تحت «المخزون» ان لم يكن له حساب.
     * اذا كان «المخزون» حساباً طرفياً عليه حركات (لا يمكن جعله أباً) يُستخدم هو نفسه.
     */
    public function warehouseAccountId(Warehouse|int $warehouse): int
    {
        $warehouse = $warehouse instanceof Warehouse ? $warehouse : Warehouse::query()->findOrFail($warehouse);

        if ($warehouse->inventory_account_id) {
            $account = Account::query()->whereKey($warehouse->inventory_account_id)->first(['id', 'is_postable', 'is_active']);

            if ($account && $account->is_postable && $account->is_active) {
                return (int) $account->id;
            }
        }

        return DB::transaction(function () use ($warehouse) {
            $parent = $this->inventoryParent();

            if (! $parent) {
                return $this->mapped(self::INVENTORY_KEY);
            }

            $account = Account::create([
                'code' => app(AccountService::class)->nextChildCode($parent->id, $parent->type),
                'parent_id' => $parent->id,
                'type' => $parent->type->value(),
                'normal_balance' => $parent->normal_balance->value(),
                'is_postable' => true,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            $nameAr = $warehouse->translate('ar')?->name ?: $warehouse->name;
            $nameEn = $warehouse->translate('en')?->name ?: $nameAr;
            $account->translateOrNew('ar')->fill(['name' => 'مخزون - '.$nameAr]);
            $account->translateOrNew('en')->fill(['name' => 'Inventory - '.$nameEn]);
            $account->save();

            $warehouse->forceFill(['inventory_account_id' => $account->id])->saveQuietly();

            return (int) $account->id;
        });
    }

    /**
     * يُنشئ حسابات المخزون الناقصة وحساب كل مخزن (يُستدعى من امر الاعداد ومن الترحيل).
     */
    public function ensureDefaults(): void
    {
        foreach (array_keys(self::DEFAULT_ACCOUNT_CODES) as $key) {
            if ($key !== self::INVENTORY_KEY) {
                $this->mapped($key);
            }
        }

        Warehouse::query()->get()->each(fn (Warehouse $warehouse) => $this->warehouseAccountId($warehouse));
    }

    /**
     * «المخزون» كحساب أب لحسابات المستودعات. الحساب الطرفي بلا حركات يُحوَّل الى أب.
     */
    private function inventoryParent(): ?Account
    {
        $mappedId = AccountMapping::query()
            ->where('source_module', self::SOURCE_MODULE)
            ->where('source_key', self::INVENTORY_KEY)
            ->value('account_id');

        $parent = $mappedId
            ? Account::query()->find($mappedId)
            : Account::query()->where('code', self::DEFAULT_ACCOUNT_CODES[self::INVENTORY_KEY])->first();

        if (! $parent) {
            return null;
        }

        if ($parent->is_postable) {
            if (JournalLine::query()->where('account_id', $parent->id)->exists()) {
                return null;
            }

            $parent->update(['is_postable' => false]);
        }

        return $parent;
    }

    private function accountIdByCode(string $code): ?int
    {
        $id = Account::query()->where('code', $code)->value('id');

        if ($id || ! isset(self::MISSING_ACCOUNTS[$code])) {
            return $id ? (int) $id : null;
        }

        $data = self::MISSING_ACCOUNTS[$code];
        $parent = Account::query()->where('code', $data['parent'])->first();

        if (! $parent || Account::withTrashed()->where('code', $code)->exists()) {
            return null;
        }

        $account = Account::create([
            'code' => $code,
            'parent_id' => $parent->id,
            'type' => $data['type']->value(),
            'normal_balance' => $data['normal_balance']->value(),
            'is_postable' => true,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        $account->translateOrNew('ar')->fill(['name' => $data['ar']]);
        $account->translateOrNew('en')->fill(['name' => $data['en']]);
        $account->save();

        return (int) $account->id;
    }
}
