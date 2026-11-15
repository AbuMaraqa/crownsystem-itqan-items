<?php

namespace Modules\Accounting\Services;

use DomainException;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ServiceCostSource;

/**
 * حسابات قيود البيع في مكان واحد (الفاتورة، المرتجع، سند القبض):
 *
 * - العميل: حسابه الفرعي تحت الذمم المدينة (PartyAccounts)، فيظهر رصيده وكشف حسابه.
 * - الايراد: حساب الصنف ان حُدد في بطاقته، والا حسب طبيعته: سلعة (ايرادات المبيعات)،
 *   خدمة (ايرادات الخدمات)، خدمة نقل (ايرادات خدمات النقل).
 * - تكلفة الخدمة (مقابلة الايراد بالتكلفة في نفس الفترة): مدين حساب تكلفة الخدمة،
 *   ودائن حسب مصدرها: داخلية (تكاليف خدمات محمّلة)، خارجية لجهة مسجلة (حساب الجهة)،
 *   خارجية لجهة غير مسجلة (مصروفات مستحقة).
 */
class SalesLedgerAccounts
{
    public const SOURCE_MODULE = 'Accounting';

    public const RECEIVABLE_KEY = 'sales_invoice_receivable';

    public const REVENUE_KEY = 'sales_invoice_revenue';

    public const TAX_KEY = 'sales_invoice_tax';

    public const SERVICE_REVENUE_KEY = 'sales_invoice_service_revenue';

    public const TRANSPORT_REVENUE_KEY = 'sales_invoice_transport_revenue';

    public const SERVICE_COST_KEY = 'sales_invoice_service_cost';

    public const TRANSPORT_COST_KEY = 'sales_invoice_transport_cost';

    public const SERVICE_COST_APPLIED_KEY = 'sales_invoice_service_cost_applied';

    public const SERVICE_COST_ACCRUED_KEY = 'sales_invoice_service_cost_accrued';

    private const DEFAULT_ACCOUNT_CODES = [
        self::RECEIVABLE_KEY => '1130',
        self::REVENUE_KEY => '4100',
        self::TAX_KEY => '2140',
        self::SERVICE_REVENUE_KEY => '4110',
        self::TRANSPORT_REVENUE_KEY => '4150',
        self::SERVICE_COST_KEY => '5160',
        self::TRANSPORT_COST_KEY => '5320',
        self::SERVICE_COST_APPLIED_KEY => '5170',
        self::SERVICE_COST_ACCRUED_KEY => '2120',
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

    /**
     * حساب العميل الفرعي، ويُنشأ ان لم يوجد تحت حساب العملاء الأب.
     */
    public function customerAccountId(?int $clientId): int
    {
        return app(PartyAccounts::class)->customerAccountId($clientId);
    }

    public function revenueAccountId(?Product $product, bool $isService): int
    {
        if ($accountId = $this->productAccountId($product?->revenue_account_id)) {
            return $accountId;
        }

        if (! $isService) {
            return $this->mapped(self::REVENUE_KEY);
        }

        return $this->mapped($product?->isTransportService() ? self::TRANSPORT_REVENUE_KEY : self::SERVICE_REVENUE_KEY);
    }

    public function serviceCostAccountId(?Product $product): int
    {
        if ($accountId = $this->productAccountId($product?->cost_account_id)) {
            return $accountId;
        }

        return $this->mapped($product?->isTransportService() ? self::TRANSPORT_COST_KEY : self::SERVICE_COST_KEY);
    }

    /**
     * الطرف الدائن لتكلفة الخدمة حسب مصدرها والجهة المنفذة.
     */
    public function serviceCostCreditAccountId(?string $costSource, ?int $providerId): int
    {
        if ($costSource !== ServiceCostSource::EXTERNAL->value) {
            return $this->mapped(self::SERVICE_COST_APPLIED_KEY);
        }

        return app(PartyAccounts::class)->accountId($providerId, PartyAccounts::SUPPLIER_PARENT_KEY) ?? $this->mapped(self::SERVICE_COST_ACCRUED_KEY);
    }

    /**
     * يوزّع صافي الايراد (بعد خصم المستند وقبل الضريبة) على حسابات البنود بنسبة
     * صافي كل بند، فيُقيَّد ايراد السلع والخدمات كلٌّ في حسابه. فرق التقريب يُحمَّل
     * على اكبر حساب ليتطابق المجموع مع المستند تماماً.
     *
     * @param  iterable<int, array{account_id: int, line_total: float|string}>  $lines
     * @return array<int, float> المبلغ لكل حساب
     */
    public function allocateRevenue(iterable $lines, float $netRevenue): array
    {
        $lines = collect($lines)->filter(fn (array $line): bool => (float) $line['line_total'] > 0)->values();
        $linesTotal = round((float) $lines->sum(fn (array $line): float => (float) $line['line_total']), 4);
        $netRevenue = round($netRevenue, 4);

        if ($linesTotal <= 0 || $netRevenue <= 0) {
            return [];
        }

        $amounts = [];

        foreach ($lines as $line) {
            $share = round((float) $line['line_total'] * $netRevenue / $linesTotal, 4);
            $amounts[$line['account_id']] = round(($amounts[$line['account_id']] ?? 0) + $share, 4);
        }

        $difference = round($netRevenue - array_sum($amounts), 4);

        if ($difference != 0.0) {
            $largest = array_keys($amounts, max($amounts))[0];
            $amounts[$largest] = round($amounts[$largest] + $difference, 4);
        }

        return array_filter($amounts, fn (float $amount): bool => $amount > 0);
    }

    private function productAccountId(mixed $accountId): ?int
    {
        if (blank($accountId)) {
            return null;
        }

        $account = Account::query()->whereKey($accountId)->first(['id', 'is_postable', 'is_active']);

        return $account && $account->is_postable && $account->is_active ? (int) $account->id : null;
    }
}
