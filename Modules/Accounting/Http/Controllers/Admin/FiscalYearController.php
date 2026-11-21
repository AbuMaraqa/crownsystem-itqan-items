<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Http\Requests\Admin\FiscalYearRequest;
use Modules\Accounting\Repositories\Contracts\FiscalYearRepositoryInterface;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;

/**
 * السنوات المالية وفتراتها الشهرية: اضافة وتعديل في نافذة، واقفال السنة (بقيد الاقفال)
 * واعادة فتحها، واقفال كل شهر واعادة فتحه.
 */
class FiscalYearController extends Controller
{
    public function __construct(
        private readonly FiscalYearRepositoryInterface $years,
        private readonly FiscalYearService $fiscalYearService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/FiscalYear/Index', [
            'table' => AdminTable::for($this->years->tableQuery())
                ->search(['name'], null)
                ->filters(AdminFilter::exact('status'), AdminFilter::boolean('is_default'), ...AdminFilter::dateRange('starts_at'))
                ->sorts('name', 'starts_at', 'ends_at', 'id')
                ->defaultSort('-starts_at')
                ->withCreator()
                ->rows(fn (FiscalYear $year) => [
                    'id' => $year->id,
                    'name' => $year->name,
                    'starts_at' => $year->starts_at?->format('d/m/Y'),
                    'ends_at' => $year->ends_at?->format('d/m/Y'),
                    'status' => StatusBadge::of($year->status),
                    'is_open' => $year->status === AccountingPeriodStatus::OPEN,
                    'is_default' => (bool) $year->is_default,
                    'periods_count' => (int) $year->periods_count,
                    'form' => [
                        'name' => $year->name,
                        'starts_at' => $year->starts_at?->toDateString(),
                        'ends_at' => $year->ends_at?->toDateString(),
                        'is_default' => (bool) $year->is_default,
                    ],
                ]),
            'statuses' => SelectOptions::from(AccountingPeriodStatus::options()),
        ]);
    }

    public function store(FiscalYearRequest $request): RedirectResponse
    {
        $this->fieldError(fn () => $this->fiscalYearService->create($request->validated()));

        return back()->with('success', __('Fiscal year created successfully'));
    }

    public function update(FiscalYearRequest $request, int $fiscalYear): RedirectResponse
    {
        /** @var FiscalYear $year */
        $year = $this->years->findOrFail($fiscalYear);

        $this->fieldError(fn () => $this->fiscalYearService->update($year, $request->validated()));

        return back()->with('success', __('Fiscal year updated successfully'));
    }

    public function close(int $fiscalYear): RedirectResponse
    {
        /** @var FiscalYear $year */
        $year = $this->years->findOrFail($fiscalYear);

        return $this->attempt(fn () => $this->fiscalYearService->close($year), __('Fiscal year closed successfully'));
    }

    public function reopen(int $fiscalYear): RedirectResponse
    {
        /** @var FiscalYear $year */
        $year = $this->years->findOrFail($fiscalYear);

        return $this->attempt(fn () => $this->fiscalYearService->reopen($year), __('Fiscal year reopened successfully'));
    }

    public function info(int $fiscalYear): JsonResponse
    {
        return response()->json(RecordInfo::of($this->years->findOrFail($fiscalYear)));
    }

    /**
     * الفترات الشهرية لسنة مالية: اقفال الشهر بعد مراجعته يمنع اي ترحيل بتاريخه.
     */
    public function periods(int $fiscalYear): Response
    {
        /** @var FiscalYear $year */
        $year = $this->years->findOrFail($fiscalYear);

        return Inertia::render('Accounting/FiscalYear/Periods', [
            'fiscalYear' => ['id' => $year->id, 'name' => $year->name],
            'table' => AdminTable::for($this->years->periodsQuery($year->id))
                ->search(['name'], null)
                ->filters(AdminFilter::exact('status'))
                ->sorts('name', 'starts_at', 'ends_at')
                ->defaultSort('starts_at')
                ->withCreator()
                ->rows(fn (AccountingPeriod $period) => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'starts_at' => $period->starts_at?->format('d/m/Y'),
                    'ends_at' => $period->ends_at?->format('d/m/Y'),
                    'status' => StatusBadge::of($period->status),
                    'is_open' => $period->status === AccountingPeriodStatus::OPEN,
                ]),
            'statuses' => SelectOptions::from(AccountingPeriodStatus::options()),
        ]);
    }

    public function closePeriod(int $period): RedirectResponse
    {
        $model = $this->years->findPeriodOrFail($period);

        return $this->attempt(fn () => $this->fiscalYearService->closePeriod($model), __('Accounting period closed successfully'));
    }

    public function reopenPeriod(int $period): RedirectResponse
    {
        $model = $this->years->findPeriodOrFail($period);

        return $this->attempt(fn () => $this->fiscalYearService->reopenPeriod($model), __('Accounting period reopened successfully'));
    }

    public function periodInfo(int $period): JsonResponse
    {
        return response()->json(RecordInfo::of($this->years->findPeriodOrFail($period)));
    }

    /**
     * النموذج في نافذة: رفض التواريخ (تداخل سنتين، سنة عليها قيود) يظهر تحت حقل البداية
     * وتبقى النافذة مفتوحة بما كُتب فيها.
     */
    private function fieldError(callable $action): void
    {
        try {
            $action();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['starts_at' => $exception->getMessage()]);
        }
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }
}
