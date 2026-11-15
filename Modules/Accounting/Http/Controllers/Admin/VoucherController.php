<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Http\Requests\Admin\VoucherRequest;
use Modules\Accounting\Repositories\Contracts\VoucherRepositoryInterface;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\PaymentVoucherService;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PartySelect;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\UserCellData;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\GeoLocation\Support\GeoFilters;
use Modules\Items\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * صفحات سند القبض وسند الصرف: نفس الحقول والجدول والاجراءات، ويختلف اسم الكيان والخدمة
 * والنصوص. القواعد كلها في الخدمة (PaymentVoucherService / ReceiptVoucherService).
 */
abstract class VoucherController extends Controller
{
    public function __construct(
        protected readonly VoucherRepositoryInterface $vouchers,
        protected readonly CurrencyExchangeRateService $rates,
    ) {}

    /** `PaymentVoucher` في `Accounting/PaymentVoucher/Index`. */
    abstract protected function page(): string;

    /** `accounting.payment-vouchers`. */
    abstract protected function routes(): string;

    /** تسلسل الرقم المرجعي وبادئته: ['payment_voucher', 'PMT']. */
    abstract protected function sequence(): array;

    abstract protected function service(): PaymentVoucherService|ReceiptVoucherService;

    /** @return class-string<VoucherRequest> */
    abstract protected function request(): string;

    /**
     * رسائل النجاح: created, updated, posted, cancelled, deleted.
     *
     * @return array<string, string>
     */
    abstract protected function messages(): array;

    /**
     * @return array<int, string> خيارات حالة السند (Enum::options())
     */
    abstract protected function statusOptions(): array;

    public function index(): Response
    {
        return Inertia::render($this->page().'/Index', [
            'table' => AdminTable::for($this->vouchers->tableQuery())
                ->search(['reference_no', 'notes', 'cheque_number'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->clientFilter(),
                    AdminFilter::text('notes'),
                    GeoFilters::integer('status'),
                    GeoFilters::integer('payment_method'),
                    GeoFilters::integer('currency', 'currency_id'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('voucher_date'),
                    ...AdminFilter::numberRange('amount'),
                )
                ->sorts('reference_no', 'voucher_date', 'amount', 'status', 'id')
                ->defaultSort('-voucher_date')
                ->rows(fn (PaymentVoucher|ReceiptVoucher $voucher) => [
                    'id' => $voucher->id,
                    'reference_no' => $voucher->reference_no,
                    'voucher_date' => $voucher->voucher_date?->format('d/m/Y'),
                    'client' => UserCellData::of($voucher->client),
                    'account' => $voucher->account?->name,
                    'payment_method' => $voucher->payment_method?->getLabel(),
                    'currency' => $voucher->currency?->code,
                    'amount' => number_format((float) $voucher->amount, 2),
                    'amount_full' => Number::money($voucher->amount),
                    'status' => StatusBadge::of($voucher->status),
                    'cheque_number' => $voucher->cheque_number,
                    'notes' => $voucher->notes,
                    'editable' => ! $voucher->trashed() && $voucher->canBeEdited(),
                    'deleted' => $voucher->trashed(),
                ]),
            ...$this->listOptions(),
        ]);
    }

    public function add(): Response
    {
        [$code, $prefix] = $this->sequence();
        $currencyId = $this->rates->baseCurrencyId();
        $today = now()->toDateString();

        return Inertia::render($this->page().'/Add', [
            'voucher' => [
                'reference_no' => app(ReferenceNumberService::class)->preview($code, now(), ['prefix' => $prefix]),
                'client_id' => null,
                'branch_id' => null,
                'account_id' => null,
                'voucher_date' => $today,
                'currency_id' => $currencyId,
                'exchange_rate' => $this->rate($currencyId, $today) ?? '1',
                'payment_method' => AccountingPaymentMethod::CASH->value(),
                'amount' => '0.0000',
                'cheque_number' => null,
                'cheque_bank_name' => null,
                'cheque_date' => null,
                'notes' => null,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $voucher = $this->service()->create($this->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route($this->routes().'.edit', $voucher->id)->with('success', $this->messages()['created']);
    }

    /**
     * صفحة السند: نموذج تعديل ما دام مسودة، ثم عرض للقراءة مع الترحيل والالغاء والطباعة.
     */
    public function edit(int $voucher): Response
    {
        /** @var PaymentVoucher|ReceiptVoucher $model */
        $model = $this->vouchers->findForPage($voucher);

        return Inertia::render($this->page().'/Edit', [
            'voucher' => [
                'id' => $model->id,
                'reference_no' => $model->reference_no,
                'client_id' => $model->client_id,
                'branch_id' => $model->branch_id,
                'account_id' => $model->account_id,
                'voucher_date' => $model->voucher_date?->toDateString(),
                'currency_id' => $model->currency_id,
                'exchange_rate' => $model->exchange_rate,
                'payment_method' => $model->payment_method?->value(),
                'amount' => $model->amount,
                'cheque_number' => $model->cheque_number,
                'cheque_bank_name' => $model->cheque_bank_name,
                'cheque_date' => $model->cheque_date?->toDateString(),
                'notes' => $model->notes,
            ],
            'record' => [
                'status' => StatusBadge::of($model->status),
                'client' => $model->client ? ['value' => $model->client->id, 'label' => (string) $model->client->name] : null,
                'account' => $model->account?->name,
                'currency' => $model->currency?->code,
                'payment_method' => $model->payment_method?->getLabel(),
                'voucher_date' => $model->voucher_date?->format('d/m/Y'),
                'amount' => Number::money($model->amount),
                'editable' => $model->canBeEdited(),
                'can_cancel' => $this->service()->canCancel($model),
                'cancelled' => $model->isCancelled(),
                'cancelled_at' => $model->cancelled_at?->format('d/m/Y H:i'),
                'cancellation_reason' => $model->cancellation_reason,
                'cheque' => $model->cheque ? StatusBadge::of($model->cheque->status) : null,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(int $voucher): RedirectResponse
    {
        try {
            $this->service()->update($this->vouchers->findForPage($voucher), $this->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $this->messages()['updated']);
    }

    /**
     * يحفظ النموذج (ما دام السند مسودة) ثم يرحّله، كما كان زر «ترحيل السند».
     */
    public function post(int $voucher): RedirectResponse
    {
        try {
            $this->service()->saveAndPost($this->vouchers->findForPage($voucher), $this->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $this->messages()['posted']);
    }

    public function cancel(Request $request, int $voucher): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $this->service()->cancel($this->vouchers->findForPage($voucher), $reason);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $this->messages()['cancelled']);
    }

    public function destroy(int $voucher): RedirectResponse
    {
        try {
            $this->service()->delete($this->vouchers->findForPage($voucher));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $this->messages()['deleted']);
    }

    public function info(int $voucher): JsonResponse
    {
        return response()->json(RecordInfo::of($this->vouchers->findForPage($voucher)));
    }

    /**
     * حقل الطرف وفلتره (RemoteSelect): الزبائن والموردون، كـ PartySelect::any().
     */
    public function parties(Request $request): JsonResponse
    {
        return response()->json(PartySelect::lookup('any', $request->string('q')->toString()));
    }

    /**
     * سعر صرف العملة في تاريخ السند، يُعرض في النموذج عند تغيير العملة او التاريخ.
     */
    public function exchangeRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        return response()->json(['rate' => $this->rate(isset($data['currency_id']) ? (int) $data['currency_id'] : null, $data['date'] ?? null)]);
    }

    /**
     * الحقول بعد التحقق بطلب السند الخاص (PaymentVoucherRequest او ReceiptVoucherRequest):
     * حلّه من الحاوية يتحقق منه كما لو كان معامل الدالة.
     *
     * @return array<string, mixed>
     */
    private function validated(): array
    {
        return app($this->request())->validated();
    }

    /**
     * الطرف بالاسم او الهاتف او البريد (بحث PartySelect نفسه).
     */
    private function clientFilter(): AllowedFilter
    {
        return AllowedFilter::callback('client', function (Builder $query, mixed $value): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas('client', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', $term)
                ->orWhereLike('phone', $term)
                ->orWhereLike('email', $term)));
        });
    }

    private function rate(?int $currencyId, ?string $date): ?string
    {
        $rate = $this->rates->rateFor($currencyId, $date ?: now()->toDateString());

        return $rate ? number_format($rate, 8, '.', '') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'accountOptions' => SelectOptions::accounts(true),
            'currencyOptions' => SelectOptions::currencies(),
            'paymentMethods' => SelectOptions::from(AccountingPaymentMethod::options()),
            'chequeMethod' => AccountingPaymentMethod::CHEQUE->value(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listOptions(): array
    {
        return [
            'statusOptions' => SelectOptions::from($this->statusOptions()),
            'currencyOptions' => SelectOptions::currencies(),
            'paymentMethods' => SelectOptions::from(AccountingPaymentMethod::options()),
        ];
    }
}
