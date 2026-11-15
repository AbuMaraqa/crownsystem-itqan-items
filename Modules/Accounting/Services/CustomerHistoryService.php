<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseInvoiceItem;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\PurchaseReturnItem;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesInvoiceItem;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Entities\SalesReturnItem;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Support\PriceResolver;

/**
 * سجل الزبون: كل ما يخص طرفاً واحداً (client_id) في مكان واحد.
 *
 * - الملخص: المبيعات والمرتجعات والمقبوضات والمشتريات بعملة الاساس (المرحّل فقط).
 * - كشف الحساب: قيود حسابه الفرعي (accounting_accounts.user_id) مع رصيد افتتاحي ورصيد جارٍ.
 * - المستندات: عروض الاسعار، الطلبيات، الفواتير، المرتجعات، السندات والشيكات.
 * - الاصناف: آخر سعر بيع وآخر سعر شراء لكل صنف تعامل به.
 */
class CustomerHistoryService
{
    public const SALES_GROUP = 'sales';

    public const PURCHASES_GROUP = 'purchases';

    public const FINANCE_GROUP = 'finance';

    /** تبويبات صفحة سجل الزبون، والاول هو الافتراضي. */
    public const TABS = ['statement', 'documents', 'items', 'movements'];

    /** نوعا كشف الحساب: عادي، او تفصيلي ببنود كل مستند. */
    public const STATEMENT_MODES = ['summary', 'detailed'];

    /**
     * مجموعات المستندات لفلتر تبويب المستندات.
     *
     * @return array<string, string>
     */
    public function documentGroups(): array
    {
        return [
            self::SALES_GROUP => __('Sales'),
            self::PURCHASES_GROUP => __('Purchases'),
            self::FINANCE_GROUP => __('Receipts and Payments'),
        ];
    }

    /**
     * الاطراف لحقل البحث (RemoteSelect): اول 30 مستخدماً بالاسم، او من يطابق اسمه او بريده او هاتفه.
     *
     * @return list<array{value: int, label: string}>
     */
    public function clientOptions(string $search = '', int $limit = 30): array
    {
        $search = trim($search);

        return User::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'phone'])
            ->map(fn (User $user) => ['value' => (int) $user->id, 'label' => $this->clientLabel($user)])
            ->values()
            ->all();
    }

    /**
     * نص الطرف في حقل البحث: الاسم ثم الهاتف ان وُجد.
     */
    public function clientLabel(User $user): string
    {
        return $user->name.(filled($user->phone) ? ' - '.$user->phone : '');
    }

    /**
     * انواع المستندات المرتبطة بالطرف، بحقل التاريخ وحقل المبلغ ومسار التعديل لكل منها.
     *
     * @return array<string, array<string, mixed>>
     */
    public function documentTypes(): array
    {
        return [
            'sales_quotation' => [
                'model' => SalesQuotation::class,
                'label' => __('Sales Quotation'),
                'group' => self::SALES_GROUP,
                'date' => 'quotation_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.sales-quotations.edit',
                'edit_permission' => 'Sales Quotation Update',
            ],
            'sales_order' => [
                'model' => SalesOrder::class,
                'label' => __('Sales Order'),
                'group' => self::SALES_GROUP,
                'date' => 'order_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.sales-orders.edit',
                'edit_permission' => 'Sales Order Update',
                'pdf_route' => 'accounting.sales-orders.pdf',
                'pdf_permission' => 'Sales Order View',
            ],
            'sales_invoice' => [
                'model' => SalesInvoice::class,
                'label' => __('Sales Invoice'),
                'group' => self::SALES_GROUP,
                'date' => 'invoice_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.sales-invoices.edit',
                'edit_permission' => 'Sales Invoice Update',
                'view_permission' => 'Sales Invoice View List',
                'pdf_route' => 'accounting.sales-invoices.pdf',
                'pdf_permission' => 'Sales Invoice View',
            ],
            'sales_return' => [
                'model' => SalesReturn::class,
                'label' => __('Sales Return'),
                'group' => self::SALES_GROUP,
                'date' => 'return_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.sales-returns.edit',
                'edit_permission' => 'Sales Return Update',
                'pdf_route' => 'accounting.sales-returns.pdf',
                'pdf_permission' => 'Sales Return View',
            ],
            'purchase_order' => [
                'model' => PurchaseOrder::class,
                'label' => __('Purchase Order'),
                'group' => self::PURCHASES_GROUP,
                'date' => 'order_date',
                // طلبية الشراء بلا اسعار؛ مبلغها في عرض السعر والفاتورة.
                'amount' => null,
                'edit_route' => 'accounting.purchase-orders.edit',
                'edit_permission' => 'Purchase Order Update',
                'pdf_route' => 'accounting.purchase-orders.pdf',
                'pdf_permission' => 'Purchase Order View',
            ],
            'purchase_quotation' => [
                'model' => PurchaseQuotation::class,
                'label' => __('Purchase Quotation'),
                'group' => self::PURCHASES_GROUP,
                'date' => 'quotation_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.purchase-quotations.edit',
                'edit_permission' => 'Purchase Quotation Update',
                'view_permission' => 'Purchase Quotation View List',
                'pdf_route' => 'accounting.purchase-quotations.pdf',
                'pdf_permission' => 'Purchase Quotation View List',
            ],
            'purchase_invoice' => [
                'model' => PurchaseInvoice::class,
                'label' => __('Purchase Invoice'),
                'group' => self::PURCHASES_GROUP,
                'date' => 'invoice_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.purchase-invoices.edit',
                'edit_permission' => 'Purchase Invoice Update',
                'view_permission' => 'Purchase Invoice View List',
                'pdf_route' => 'accounting.purchase-invoices.pdf',
                'pdf_permission' => 'Purchase Invoice View',
            ],
            'purchase_return' => [
                'model' => PurchaseReturn::class,
                'label' => __('Purchase Return'),
                'group' => self::PURCHASES_GROUP,
                'date' => 'return_date',
                'amount' => 'total_amount',
                'edit_route' => 'accounting.purchase-returns.edit',
                'edit_permission' => 'Purchase Return Update',
                'pdf_route' => 'accounting.purchase-returns.pdf',
                'pdf_permission' => 'Purchase Return View',
            ],
            'receipt_voucher' => [
                'model' => ReceiptVoucher::class,
                'label' => __('Receipt Voucher'),
                'group' => self::FINANCE_GROUP,
                'date' => 'voucher_date',
                'amount' => 'amount',
                'edit_route' => 'accounting.receipt-vouchers.edit',
                'edit_permission' => 'Receipt Voucher Update',
                'pdf_route' => 'accounting.receipt-vouchers.pdf',
                'pdf_permission' => 'Receipt Voucher View',
            ],
            'payment_voucher' => [
                'model' => PaymentVoucher::class,
                'label' => __('Payment Voucher'),
                'group' => self::FINANCE_GROUP,
                'date' => 'voucher_date',
                'amount' => 'amount',
                'edit_route' => 'accounting.payment-vouchers.edit',
                'edit_permission' => 'Payment Voucher Update',
                'pdf_route' => 'accounting.payment-vouchers.pdf',
                'pdf_permission' => 'Payment Voucher View',
            ],
            'cheque' => [
                'model' => Cheque::class,
                'label' => __('Cheque'),
                'group' => self::FINANCE_GROUP,
                'date' => 'cheque_date',
                'amount' => 'amount',
                'reference' => 'cheque_number',
            ],
        ];
    }

    /**
     * ارقام الملخص بعملة الاساس. المستندات المالية تُحتسب عند ترحيلها فقط،
     * فالمسودة لم تُثبت بعد في الدفاتر.
     *
     * @return array<string, mixed>
     */
    public function summary(int $clientId, ?string $from = null, ?string $to = null): array
    {
        $sales = $this->baseTotal(SalesInvoice::class, 'invoice_date', 'total_amount', $clientId, $from, $to, SalesInvoiceStatus::POSTED->value);
        $salesReturns = $this->baseTotal(SalesReturn::class, 'return_date', 'total_amount', $clientId, $from, $to, SalesReturnStatus::POSTED->value);
        $purchases = $this->baseTotal(PurchaseInvoice::class, 'invoice_date', 'total_amount', $clientId, $from, $to, PurchaseInvoiceStatus::POSTED->value);
        $purchaseReturns = $this->baseTotal(PurchaseReturn::class, 'return_date', 'total_amount', $clientId, $from, $to, PurchaseReturnStatus::POSTED->value);
        $receipts = $this->baseTotal(ReceiptVoucher::class, 'voucher_date', 'amount', $clientId, $from, $to, ReceiptVoucherStatus::POSTED->value);
        $payments = $this->baseTotal(PaymentVoucher::class, 'voucher_date', 'amount', $clientId, $from, $to, PaymentVoucherStatus::POSTED->value);

        $lastSale = SalesInvoice::query()
            ->where('client_id', $clientId)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first(['id', 'invoice_date', 'reference_no']);

        return [
            'sales' => $sales,
            'sales_returns' => $salesReturns,
            'net_sales' => round($sales - $salesReturns, 4),
            'receipts' => $receipts,
            'purchases' => $purchases,
            'purchase_returns' => $purchaseReturns,
            'net_purchases' => round($purchases - $purchaseReturns, 4),
            'payments' => $payments,
            'open_sales_orders' => SalesOrder::query()
                ->where('client_id', $clientId)
                ->whereIn('status', [
                    SalesOrderStatus::DRAFT->value,
                    SalesOrderStatus::CONFIRMED->value,
                    SalesOrderStatus::PREPARING->value,
                ])
                ->count(),
            'cheques_under_collection' => (float) Cheque::query()
                ->where('client_id', $clientId)
                ->where('status', ChequeStatus::UNDER_COLLECTION->value)
                ->selectRaw('COALESCE(SUM(amount * exchange_rate), 0) as total')
                ->value('total'),
            'last_sale_date' => $lastSale?->invoice_date?->toDateString(),
            'last_sale_reference' => $lastSale?->reference_no,
        ];
    }

    /**
     * كشف حساب الطرف من قيود حسابه الفرعي، بنفس حالات القيود التي يعرضها دفتر الاستاذ.
     * الكشف التفصيلي يضيف تحت كل حركة بنود مستندها (اصناف الفاتورة او بيانات السند).
     *
     * @return array{has_account: bool, opening: float, closing: float, debit: float, credit: float, rows: array<int, array<string, mixed>>}
     */
    public function statement(int $clientId, ?string $from = null, ?string $to = null, bool $detailed = false): array
    {
        $accountIds = Account::query()->where('user_id', $clientId)->pluck('id')->all();

        if ($accountIds === []) {
            return ['has_account' => false, 'opening' => 0.0, 'closing' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'rows' => []];
        }

        $lineTable = (new JournalLine)->getTable();

        $base = fn () => JournalLine::query()
            ->join((new JournalEntry)->getTable().' as statement_entries', 'statement_entries.id', '=', "{$lineTable}.journal_entry_id")
            ->whereNull('statement_entries.deleted_at')
            ->whereIn('statement_entries.status', [
                JournalEntryStatus::POSTED->value(),
                JournalEntryStatus::VOIDED->value(),
            ])
            ->whereIn("{$lineTable}.account_id", $accountIds);

        $opening = $from
            ? (float) $base()
                ->where('statement_entries.entry_date', '<', $from)
                ->selectRaw("COALESCE(SUM({$lineTable}.debit_base - {$lineTable}.credit_base), 0) as balance")
                ->value('balance')
            : 0.0;

        $lines = $base()
            ->when($from, fn ($query) => $query->where('statement_entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('statement_entries.entry_date', '<=', $to))
            ->orderBy('statement_entries.entry_date')
            ->orderBy('statement_entries.id')
            ->orderBy("{$lineTable}.id")
            ->get([
                "{$lineTable}.id",
                "{$lineTable}.debit_base",
                "{$lineTable}.credit_base",
                "{$lineTable}.description as line_description",
                'statement_entries.id as entry_id',
                'statement_entries.entry_date',
                'statement_entries.entry_number',
                'statement_entries.reference_no',
                'statement_entries.description as entry_description',
                'statement_entries.sourceable_type',
                'statement_entries.sourceable_id',
                'statement_entries.status as entry_status',
            ]);

        $types = collect($this->documentTypes())->keyBy('model');
        $sources = $this->statementSources($lines, $types, $detailed);
        $user = auth()->user();

        $balance = $opening;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $detailedEntries = [];

        $rows = $lines->map(function ($line) use (&$balance, &$totalDebit, &$totalCredit, &$detailedEntries, $types, $sources, $detailed, $user) {
            $debit = (float) $line->debit_base;
            $credit = (float) $line->credit_base;
            $balance = round($balance + $debit - $credit, 4);
            $totalDebit += $debit;
            $totalCredit += $credit;

            $type = $types->get($line->sourceable_type);
            $source = $sources[$line->sourceable_type][$line->sourceable_id] ?? null;
            // في الكشف التفصيلي تظهر بنود المستند مرة واحدة حتى لو مسّ القيد الحساب باكثر من سطر.
            $showDetails = $detailed && $source && ! isset($detailedEntries[$line->entry_id]);
            $detailedEntries[$line->entry_id] = true;

            return [
                'id' => (int) $line->id,
                'date' => substr((string) $line->entry_date, 0, 10),
                'entry_number' => $line->entry_number,
                'reference_no' => $line->reference_no,
                'source' => $type['label'] ?? __('Journal Entry'),
                'description' => $line->line_description ?: $line->entry_description,
                'voided' => (int) $line->entry_status === JournalEntryStatus::VOIDED->value(),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
                'url' => $type && $source ? $this->documentUrl($type, $source, $user, false) : null,
                'pdf_url' => $type && $source ? $this->pdfUrl($type, $source, $user) : null,
                'details' => $showDetails ? $this->statementDetails($source) : [],
            ];
        })->all();

        return [
            'has_account' => true,
            'opening' => round($opening, 4),
            'closing' => round($balance, 4),
            'debit' => round($totalDebit, 4),
            'credit' => round($totalCredit, 4),
            'rows' => $rows,
        ];
    }

    /**
     * كل مستندات الطرف مرتبة من الاحدث، مع رابط فتح المستند حسب صلاحية المستخدم.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documents(int $clientId, ?string $from = null, ?string $to = null, ?string $group = null, string $search = ''): Collection
    {
        $search = trim($search);
        $user = auth()->user();

        return collect($this->documentTypes())
            ->filter(fn (array $type) => blank($group) || $type['group'] === $group)
            ->flatMap(function (array $type, string $key) use ($clientId, $from, $to, $search, $user) {
                /** @var class-string<Model> $model */
                $model = $type['model'];
                $referenceColumn = $type['reference'] ?? 'reference_no';

                return $model::query()
                    ->with(array_filter([
                        'currency',
                        method_exists($model, 'journalEntry') ? 'journalEntry' : null,
                    ]))
                    ->where('client_id', $clientId)
                    ->when($from, fn ($query) => $query->whereDate($type['date'], '>=', $from))
                    ->when($to, fn ($query) => $query->whereDate($type['date'], '<=', $to))
                    ->when($search !== '', fn ($query) => $query->where(function ($query) use ($referenceColumn, $search) {
                        $query->where($referenceColumn, 'like', "%{$search}%")
                            ->orWhere('notes', 'like', "%{$search}%");
                    }))
                    ->get()
                    ->map(fn (Model $record) => [
                        'key' => $key.'-'.$record->getKey(),
                        'type' => $key,
                        'type_label' => $type['label'],
                        'group' => $type['group'],
                        'date' => $record->{$type['date']}?->toDateString(),
                        'reference_no' => $record->{$referenceColumn},
                        'status_label' => $record->status?->getLabel(),
                        'status_color' => method_exists($record->status, 'getColor') ? $record->status->getColor() : 'gray',
                        'direction' => $key === 'cheque' ? $record->direction?->getLabel() : null,
                        'amount' => $type['amount'] ? (float) $record->{$type['amount']} : null,
                        'currency' => $record->currency?->code,
                        'base_amount' => $type['amount'] ? round((float) $record->{$type['amount']} * (float) ($record->exchange_rate ?: 1), 4) : null,
                        'url' => $this->documentUrl($type, $record, $user),
                        'pdf_url' => $this->pdfUrl($type, $record, $user),
                        'sort' => ($record->{$type['date']}?->format('Ymd') ?? '00000000').str_pad((string) $record->getKey(), 10, '0', STR_PAD_LEFT),
                    ]);
            })
            ->sortByDesc('sort')
            ->values();
    }

    /**
     * آخر سعر بيع وآخر سعر شراء لكل صنف تعامل به الطرف، مع عدد مرات البيع والكمية.
     * "آخر" حسب تاريخ الفاتورة ثم ترتيب ادخالها، والسعر بعملة الفاتورة كما سُجّل.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function itemPrices(int $clientId, string $search = ''): Collection
    {
        $sales = $this->invoiceLines(SalesInvoiceItem::class, SalesInvoice::class, 'sales_invoice_id', 'invoice_date', $clientId);
        $purchases = $this->invoiceLines(PurchaseInvoiceItem::class, PurchaseInvoice::class, 'purchase_invoice_id', 'invoice_date', $clientId);

        $productIds = $sales->pluck('product_id')->merge($purchases->pluck('product_id'))->unique()->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->with('translations')
            ->whereIn('id', $productIds)
            ->get(['id', 'sku', 'barcode'])
            ->keyBy('id');

        $salesByProduct = $sales->groupBy('product_id');
        $purchasesByProduct = $purchases->groupBy('product_id');
        $search = mb_strtolower(trim($search));

        return $productIds
            ->map(function (int $productId) use ($products, $salesByProduct, $purchasesByProduct) {
                $product = $products->get($productId);
                $saleLines = $salesByProduct->get($productId, collect());
                $purchaseLines = $purchasesByProduct->get($productId, collect());
                $lastSale = $saleLines->first();
                $lastPurchase = $purchaseLines->first();

                return [
                    'product_id' => $productId,
                    'name' => $product?->name ?? $lastSale?->description ?? $lastPurchase?->description ?? '#'.$productId,
                    'sku' => $product?->sku,
                    'barcode' => $product?->barcode,
                    'last_sale_price' => $lastSale ? (float) $lastSale->unit_price : null,
                    'last_sale_discount' => $lastSale ? (float) $lastSale->discount_amount : null,
                    'last_sale_date' => $lastSale ? substr((string) $lastSale->document_date, 0, 10) : null,
                    'last_sale_reference' => $lastSale?->reference_no,
                    'last_sale_currency' => $lastSale?->currency_code,
                    'sales_count' => $saleLines->pluck('document_id')->unique()->count(),
                    'sold_quantity' => round((float) $saleLines->sum('quantity'), 4),
                    'min_sale_price' => $saleLines->isNotEmpty() ? (float) $saleLines->min('unit_price') : null,
                    'max_sale_price' => $saleLines->isNotEmpty() ? (float) $saleLines->max('unit_price') : null,
                    'last_purchase_price' => $lastPurchase ? (float) $lastPurchase->unit_price : null,
                    'last_purchase_date' => $lastPurchase ? substr((string) $lastPurchase->document_date, 0, 10) : null,
                    'last_purchase_currency' => $lastPurchase?->currency_code,
                    'sort' => max(
                        $lastSale ? substr((string) $lastSale->document_date, 0, 10) : '',
                        $lastPurchase ? substr((string) $lastPurchase->document_date, 0, 10) : '',
                    ),
                ];
            })
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['name'].' '.$row['sku'].' '.$row['barcode']), $search)))
            ->sortByDesc('sort')
            ->values();
    }

    /**
     * آخر سعر بيع للطرف لكل صنف مطلوب، لاقتراحه عند البيع.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, array{price: float, date: string, reference_no: ?string, currency: ?string}>
     */
    public function lastSalePrices(int $clientId, array $productIds): array
    {
        return $this->invoiceLines(SalesInvoiceItem::class, SalesInvoice::class, 'sales_invoice_id', 'invoice_date', $clientId, $productIds)
            ->unique('product_id')
            ->mapWithKeys(fn ($line) => [(int) $line->product_id => [
                'price' => (float) $line->unit_price,
                'date' => substr((string) $line->document_date, 0, 10),
                'reference_no' => $line->reference_no,
                'currency' => $line->currency_code,
            ]])
            ->all();
    }

    /**
     * آخر سعر للطرف لكل صنف من اخر سطر في فاتورة مرحّلة (الجهة: بيع او شراء)، بعملة
     * فاتورته مع سعر صرفها. لا جدول نسخة للاسعار: المصدر سطور المستندات نفسها.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, array{price: float, exchange_rate: float}>
     */
    public function lastPostedPrices(int $clientId, array $productIds, string $side): array
    {
        $lines = $side === PriceResolver::BUY
            ? $this->invoiceLines(PurchaseInvoiceItem::class, PurchaseInvoice::class, 'purchase_invoice_id', 'invoice_date', $clientId, $productIds, status: PurchaseInvoiceStatus::POSTED->value)
            : $this->invoiceLines(SalesInvoiceItem::class, SalesInvoice::class, 'sales_invoice_id', 'invoice_date', $clientId, $productIds, status: SalesInvoiceStatus::POSTED->value);

        return $lines
            ->unique('product_id')
            ->mapWithKeys(fn ($line) => [(int) $line->product_id => [
                'price' => (float) $line->unit_price,
                'exchange_rate' => (float) $line->document_rate ?: 1.0,
            ]])
            ->all();
    }

    /**
     * حركة الاصناف التفصيلية: بنود فواتير ومرتجعات البيع والشراء.
     * البحث يطابق اسم الصنف او رمزه او باركوده او وصف البند او رقم المستند.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function itemMovements(int $clientId, ?string $from = null, ?string $to = null, ?int $productId = null, string $search = ''): Collection
    {
        $sources = [
            [SalesInvoiceItem::class, SalesInvoice::class, 'sales_invoice_id', 'invoice_date', __('Sales Invoice'), 'out'],
            [SalesReturnItem::class, SalesReturn::class, 'sales_return_id', 'return_date', __('Sales Return'), 'in'],
            [PurchaseInvoiceItem::class, PurchaseInvoice::class, 'purchase_invoice_id', 'invoice_date', __('Purchase Invoice'), 'in'],
            [PurchaseReturnItem::class, PurchaseReturn::class, 'purchase_return_id', 'return_date', __('Purchase Return'), 'out'],
        ];

        $lines = collect($sources)->flatMap(function (array $source) use ($clientId, $from, $to, $productId) {
            [$itemModel, $documentModel, $foreignKey, $dateColumn, $label, $direction] = $source;

            return $this->invoiceLines($itemModel, $documentModel, $foreignKey, $dateColumn, $clientId, $productId ? [$productId] : null, $from, $to, false)
                ->map(fn ($line) => [
                    'key' => $foreignKey.'-'.$line->id,
                    'type_label' => $label,
                    'direction' => $direction,
                    'product_id' => $line->product_id ? (int) $line->product_id : null,
                    'description' => $line->description,
                    'date' => substr((string) $line->document_date, 0, 10),
                    'reference_no' => $line->reference_no,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'discount_amount' => (float) $line->discount_amount,
                    'line_total' => (float) $line->line_total,
                    'currency' => $line->currency_code,
                    // حالة "مرحّل" بنفس القيمة في الفواتير والمرتجعات بنوعيها.
                    'posted' => (int) $line->document_status === SalesInvoiceStatus::POSTED->value,
                    'sort' => substr((string) $line->document_date, 0, 10).str_pad((string) $line->id, 10, '0', STR_PAD_LEFT),
                ]);
        });

        $products = Product::query()
            ->with('translations')
            ->whereIn('id', $lines->pluck('product_id')->filter()->unique())
            ->get(['id', 'sku', 'barcode'])
            ->keyBy('id');
        $search = mb_strtolower(trim($search));

        return $lines
            ->map(fn (array $line) => [...$line, 'name' => $products->get($line['product_id'])?->name ?? $line['description']])
            ->when($search !== '', fn (Collection $rows) => $rows->filter(function (array $line) use ($products, $search) {
                $product = $products->get($line['product_id']);
                $haystack = implode(' ', [$line['name'], $line['description'], $line['reference_no'], $product?->sku, $product?->barcode]);

                return str_contains(mb_strtolower($haystack), $search);
            }))
            ->sortByDesc('sort')
            ->values();
    }

    /**
     * بنود مستند (فاتورة او مرتجع) للطرف مرتبة من الاحدث، مع بيانات رأس المستند.
     *
     * @param  class-string<Model>  $itemModel
     * @param  class-string<Model>  $documentModel
     * @param  array<int, int|string>|null  $productIds
     * @return Collection<int, object>
     */
    private function invoiceLines(
        string $itemModel,
        string $documentModel,
        string $foreignKey,
        string $dateColumn,
        int $clientId,
        ?array $productIds = null,
        ?string $from = null,
        ?string $to = null,
        bool $onlyProducts = true,
        ?int $status = null,
    ): Collection {
        $itemTable = (new $itemModel)->getTable();
        $documentTable = (new $documentModel)->getTable();
        $currencyTable = (new Currency)->getTable();

        return $itemModel::query()
            ->join("{$documentTable} as history_documents", 'history_documents.id', '=', "{$itemTable}.{$foreignKey}")
            ->leftJoin("{$currencyTable} as history_currencies", 'history_currencies.id', '=', 'history_documents.currency_id')
            ->whereNull('history_documents.deleted_at')
            ->where('history_documents.client_id', $clientId)
            ->when($status !== null, fn ($query) => $query->where('history_documents.status', $status))
            ->when($onlyProducts, fn ($query) => $query->whereNotNull("{$itemTable}.product_id"))
            ->when($productIds !== null, fn ($query) => $query->whereIn("{$itemTable}.product_id", array_map('intval', $productIds)))
            ->when($from, fn ($query) => $query->whereDate("history_documents.{$dateColumn}", '>=', $from))
            ->when($to, fn ($query) => $query->whereDate("history_documents.{$dateColumn}", '<=', $to))
            ->orderByDesc("history_documents.{$dateColumn}")
            ->orderByDesc('history_documents.id')
            ->orderByDesc("{$itemTable}.id")
            ->toBase()
            ->get([
                "{$itemTable}.id",
                "{$itemTable}.product_id",
                "{$itemTable}.description",
                "{$itemTable}.quantity",
                "{$itemTable}.unit_price",
                "{$itemTable}.discount_amount",
                "{$itemTable}.line_total",
                'history_documents.id as document_id',
                "history_documents.{$dateColumn} as document_date",
                'history_documents.reference_no',
                'history_documents.status as document_status',
                'history_documents.exchange_rate as document_rate',
                'history_currencies.code as currency_code',
            ]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function baseTotal(string $model, string $dateColumn, string $amountColumn, int $clientId, ?string $from, ?string $to, int $status): float
    {
        return round((float) $model::query()
            ->where('client_id', $clientId)
            ->where('status', $status)
            ->when($from, fn ($query) => $query->whereDate($dateColumn, '>=', $from))
            ->when($to, fn ($query) => $query->whereDate($dateColumn, '<=', $to))
            ->selectRaw("COALESCE(SUM({$amountColumn} * exchange_rate), 0) as total")
            ->value('total'), 4);
    }

    /**
     * مستندات حركات الكشف محمّلة دفعة واحدة لكل نوع: [class => [id => Model]].
     *
     * @param  Collection<int, mixed>  $lines
     * @param  Collection<string, array<string, mixed>>  $types
     * @return array<string, Collection<int, Model>>
     */
    private function statementSources(Collection $lines, Collection $types, bool $detailed): array
    {
        return $lines
            ->filter(fn ($line) => $line->sourceable_id && $types->has($line->sourceable_type))
            ->groupBy('sourceable_type')
            ->map(function (Collection $group, string $model) use ($detailed) {
                /** @var class-string<Model> $model */
                return $model::query()
                    ->with(array_filter([
                        'currency',
                        $detailed && method_exists($model, 'items') ? 'items' : null,
                    ]))
                    ->whereIn('id', $group->pluck('sourceable_id')->unique()->all())
                    ->get()
                    ->keyBy('id');
            })
            ->all();
    }

    /**
     * سطور الكشف التفصيلي: بنود الفاتورة/المردود، او طريقة الدفع وبيانات الشيك للسند.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statementDetails(Model $source): array
    {
        $currency = $source->currency?->code;

        if ($source->relationLoaded('items')) {
            return $source->items->map(fn (Model $item) => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'currency' => $currency,
            ])->all();
        }

        if (! isset($source->payment_method)) {
            return [];
        }

        return [[
            'description' => collect([
                $source->payment_method?->getLabel(),
                filled($source->cheque_number) ? __('Cheque Number').': '.$source->cheque_number : null,
                filled($source->cheque_bank_name) ? $source->cheque_bank_name : null,
                $source->cheque_date?->toDateString(),
                $source->notes,
            ])->filter()->implode(' · '),
            'quantity' => null,
            'unit_price' => null,
            'line_total' => (float) $source->amount,
            'currency' => $currency,
        ]];
    }

    /**
     * رابط فتح المستند: صفحة التعديل للمسودة، وصفحة التفاصيل للقراءة بعد الترحيل.
     */
    private function documentUrl(array $type, Model $record, $user, bool $fallbackToPdf = true): ?string
    {
        $editable = ! method_exists($record, 'canBeEdited') || $record->canBeEdited();

        if (isset($type['edit_route'])) {
            if ($editable && $user?->can($type['edit_permission'])) {
                return route($type['edit_route'], $record);
            }

            if (isset($type['view_permission']) && $user?->can($type['view_permission'])) {
                return route($type['edit_route'], $record);
            }
        }

        return $fallbackToPdf ? $this->pdfUrl($type, $record, $user) : null;
    }

    private function pdfUrl(array $type, Model $record, $user): ?string
    {
        return isset($type['pdf_route']) && $user?->can($type['pdf_permission'])
            ? route($type['pdf_route'], $record)
            : null;
    }
}
