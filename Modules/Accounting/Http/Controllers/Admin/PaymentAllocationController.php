<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Http\Requests\Admin\PaymentAllocationRequest;
use Modules\Accounting\Repositories\Contracts\PaymentAllocationRepositoryInterface;
use Modules\Accounting\Services\PaymentAllocationService;

/**
 * افعال لوحة تخصيص الدفعات (تُعرض داخل صفحة السند او المرتجع او الفاتورة، وبياناتها من
 * PaymentAllocationPanel): تخصيص على فاتورة، تخصيص تلقائي الاقدم اولاً، وفك تخصيص.
 */
class PaymentAllocationController extends Controller
{
    public function __construct(
        private readonly PaymentAllocationRepositoryInterface $allocations,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    public function allocate(PaymentAllocationRequest $request, string $sourceType, int $source): RedirectResponse
    {
        $document = $this->allocations->findSourceOrFail($sourceType, $source);
        $data = $request->validated();

        return $this->attempt(fn () => $this->allocationService->allocateToOpenInvoice($document, (int) $data['invoice_id'], $data['amount'] ?? null));
    }

    public function allocateAutomatically(string $sourceType, int $source): RedirectResponse
    {
        $document = $this->allocations->findSourceOrFail($sourceType, $source);

        return $this->attempt(fn () => $this->allocationService->allocateAutomatically($document));
    }

    public function destroy(int $allocation): RedirectResponse
    {
        /** @var PaymentAllocation $model */
        $model = $this->allocations->findOrFail($allocation);

        try {
            $this->allocationService->deallocate($model);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Allocation removed successfully.'));
    }

    private function attempt(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Payment allocated successfully.'));
    }
}
