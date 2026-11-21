<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Enums\ChequeDirection;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Accounting\Http\Requests\Admin\ChequeRequest;
use Modules\Accounting\Repositories\Contracts\ChequeRepositoryInterface;
use Modules\Accounting\Services\ChequeService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * سجل الشيكات: الشيك تحت التحصيل يُحصَّل (بقيد على الحساب البنكي) او يُرتجع او يُلغى
 * (بعكس قيد سنده والغاء السند).
 */
class ChequeController extends Controller
{
    public function __construct(
        private readonly ChequeRepositoryInterface $cheques,
        private readonly ChequeService $chequeService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Cheque/Index', [
            'table' => AdminTable::for($this->cheques->tableQuery())
                ->search(['cheque_number', 'bank_name'], null)
                ->filters(
                    AdminFilter::text('cheque_number'),
                    // الطرف بالاسم او الهاتف او البريد، كبحث حقل الطرف (PartySelect).
                    AllowedFilter::callback('client', fn (Builder $query, mixed $value) => $query->whereHas('client', function (Builder $query) use ($value): void {
                        $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';
                        $query->where(fn (Builder $query) => $query->whereLike('name', $term)->orWhereLike('phone', $term)->orWhereLike('email', $term));
                    })),
                    AdminFilter::text('bank_name'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('direction'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('cheque_date'),
                )
                ->sorts('cheque_number', 'cheque_date', 'amount', 'id')
                ->defaultSort('-cheque_date')
                ->withCreator()
                ->rows(fn (Cheque $cheque) => [
                    'id' => $cheque->id,
                    'cheque_number' => $cheque->cheque_number,
                    'bank_name' => $cheque->bank_name,
                    'direction' => StatusBadge::of($cheque->direction),
                    'cheque_date' => $cheque->cheque_date?->format('d/m/Y'),
                    'client' => UserCellData::of($cheque->client),
                    'account' => $cheque->account?->name,
                    'clearing_account' => $cheque->clearingAccount?->name,
                    'currency' => $cheque->currency?->code,
                    'amount' => Number::money($cheque->amount),
                    'status' => StatusBadge::of($cheque->status),
                    'is_under_collection' => $cheque->status === ChequeStatus::UNDER_COLLECTION && ! $cheque->trashed(),
                    'notes' => $cheque->notes,
                ]),
            'statuses' => SelectOptions::from(ChequeStatus::options()),
            'directions' => SelectOptions::from(ChequeDirection::options()),
            'currencies' => SelectOptions::currencies(),
            'bankAccounts' => SelectOptions::accounts(true),
        ]);
    }

    public function clear(ChequeRequest $request, int $cheque): RedirectResponse
    {
        return $this->attempt(
            fn (Cheque $model) => $this->chequeService->markCleared($model, (int) $request->validated('clearing_account_id')),
            $cheque,
            __('Cheque marked as cleared successfully'),
        );
    }

    public function bounce(int $cheque): RedirectResponse
    {
        return $this->attempt(
            fn (Cheque $model) => $this->chequeService->markBounced($model),
            $cheque,
            __('Cheque marked as bounced successfully'),
        );
    }

    public function cancel(int $cheque): RedirectResponse
    {
        return $this->attempt(
            fn (Cheque $model) => $this->chequeService->cancel($model),
            $cheque,
            __('Cheque cancelled successfully'),
        );
    }

    public function info(int $cheque): JsonResponse
    {
        return response()->json(RecordInfo::of($this->cheques->findWithTrashedOrFail($cheque)));
    }

    /**
     * @param  callable(Cheque): mixed  $action
     */
    private function attempt(callable $action, int $id, string $message): RedirectResponse
    {
        /** @var Cheque $model */
        $model = $this->cheques->findOrFail($id);

        try {
            $action($model);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }
}
