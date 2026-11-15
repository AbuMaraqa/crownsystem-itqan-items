<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\PeriodClosingVoucher;
use Modules\Accounting\Enums\PeriodClosingVoucherStatus;
use Modules\Accounting\Repositories\Contracts\PeriodClosingVoucherRepositoryInterface;
use Modules\Accounting\Services\PeriodClosingVoucherService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Items\Support\RecordInfo;

/**
 * قيود اقفال السنوات (تُنشأ تلقائياً عند اقفال السنة المالية)، والغاؤها بقيد عكسي.
 */
class PeriodClosingVoucherController extends Controller
{
    public function __construct(
        private readonly PeriodClosingVoucherRepositoryInterface $vouchers,
        private readonly PeriodClosingVoucherService $closingService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/PeriodClosingVoucher/Index', [
            'table' => AdminTable::for($this->vouchers->tableQuery())
                ->search(['reference_no'], null)
                ->filters(AdminFilter::exact('status'), AdminFilter::exact('fiscal_year', 'fiscal_year_id'), ...AdminFilter::dateRange('closing_date'))
                ->sorts('reference_no', 'closing_date', 'net_profit_base', 'id')
                ->defaultSort('-closing_date')
                ->rows(fn (PeriodClosingVoucher $voucher) => [
                    'id' => $voucher->id,
                    'reference_no' => $voucher->reference_no,
                    'fiscal_year' => $voucher->fiscalYear?->name,
                    'closing_date' => $voucher->closing_date?->format('d/m/Y'),
                    'net_profit' => Number::money($voucher->net_profit_base),
                    'is_profit' => (float) $voucher->net_profit_base >= 0,
                    'journal_entry' => $voucher->journalEntry ? [
                        'id' => $voucher->journalEntry->id,
                        'entry_number' => $voucher->journalEntry->entry_number,
                    ] : null,
                    'status' => StatusBadge::of($voucher->status),
                    'is_posted' => $voucher->status === PeriodClosingVoucherStatus::POSTED,
                    'created_by' => UserCellData::of($voucher->createdBy),
                ]),
            'statuses' => SelectOptions::from(PeriodClosingVoucherStatus::options()),
            'fiscalYears' => SelectOptions::from($this->vouchers->fiscalYearOptions()->all()),
        ]);
    }

    public function cancel(int $periodClosingVoucher): RedirectResponse
    {
        /** @var PeriodClosingVoucher $voucher */
        $voucher = $this->vouchers->findOrFail($periodClosingVoucher);

        try {
            $this->closingService->cancel($voucher);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Closing voucher cancelled successfully'));
    }

    public function info(int $periodClosingVoucher): JsonResponse
    {
        return response()->json(RecordInfo::of($this->vouchers->findOrFail($periodClosingVoucher)));
    }
}
