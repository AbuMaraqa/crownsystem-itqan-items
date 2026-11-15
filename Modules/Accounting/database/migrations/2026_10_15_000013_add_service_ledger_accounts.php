<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

/**
 * حسابات بيع الخدمات وربطها، لقواعد البيانات القائمة (المثبتة الجديدة تأخذها من
 * الـ seeders). لا يُعدَّل حساب او ربط موجود، فما غيّره المستخدم يبقى كما هو.
 * الحساب لا يُنشأ ان لم يوجد حسابه الأب، والربط بلا حساب يُكمَّل عند اول ترحيل.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['code' => '4150', 'parent' => '4000', 'type' => AccountType::REVENUE, 'normal_balance' => NormalBalance::CREDIT, 'en' => 'Transport Service Revenue', 'ar' => 'إيرادات خدمات النقل'],
        ['code' => '5160', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'normal_balance' => NormalBalance::DEBIT, 'en' => 'Cost of Services Rendered', 'ar' => 'تكلفة الخدمات المقدمة'],
        ['code' => '5170', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'normal_balance' => NormalBalance::CREDIT, 'en' => 'Applied Internal Service Costs', 'ar' => 'تكاليف خدمات داخلية محمّلة'],
    ];

    private const MAPPINGS = [
        'sales_invoice_service_revenue' => '4110',
        'sales_invoice_transport_revenue' => '4150',
        'sales_invoice_service_cost' => '5160',
        'sales_invoice_transport_cost' => '5320',
        'sales_invoice_service_cost_applied' => '5170',
        'sales_invoice_service_cost_accrued' => '2120',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(config('accounting.table_prefix').'accounts')) {
            return;
        }

        foreach (self::ACCOUNTS as $data) {
            if (Account::withTrashed()->where('code', $data['code'])->exists()) {
                continue;
            }

            $parent = Account::query()->where('code', $data['parent'])->first();

            if (! $parent) {
                continue;
            }

            $account = Account::create([
                'code' => $data['code'],
                'parent_id' => $parent->id,
                'type' => $data['type']->value(),
                'normal_balance' => $data['normal_balance']->value(),
                'sort_order' => 0,
                'is_postable' => true,
                'is_active' => true,
                'created_by' => null,
            ]);

            $account->translateOrNew('en')->fill(['name' => $data['en']]);
            $account->translateOrNew('ar')->fill(['name' => $data['ar']]);
            $account->save();
        }

        foreach (self::MAPPINGS as $key => $code) {
            $mapping = AccountMapping::query()->firstOrCreate(
                ['source_module' => 'Accounting', 'source_key' => $key],
                ['account_id' => null, 'created_by' => null],
            );

            if (! $mapping->account_id) {
                $mapping->update(['account_id' => Account::query()->where('code', $code)->value('id')]);
            }
        }
    }

    public function down(): void
    {
        // الحسابات قد تحمل قيوداً بعد الترحيل، فلا تُحذف عند التراجع.
        AccountMapping::query()
            ->where('source_module', 'Accounting')
            ->whereIn('source_key', array_keys(self::MAPPINGS))
            ->delete();
    }
};
