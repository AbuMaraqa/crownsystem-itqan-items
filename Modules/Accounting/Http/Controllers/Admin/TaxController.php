<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Http\Requests\Admin\TaxRequest;
use Modules\Accounting\Repositories\Contracts\TaxRepositoryInterface;
use Modules\Accounting\Services\TaxService;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;
use Modules\Core\Support\RecordInfo;

/**
 * الضرائب: الاضافة والتعديل في نافذة على صفحة القائمة، والتفعيل من الجدول.
 */
class TaxController extends Controller
{
    use ReordersRecords;

    public function __construct(
        private readonly TaxRepositoryInterface $taxes,
        private readonly TaxService $taxService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Tax/Index', [
            'table' => AdminTable::for($this->taxes->tableQuery())
                ->search(['name', 'code'], translated: null)
                ->filters(
                    AdminFilter::boolean('is_active'),
                    AdminFilter::text('code'),
                    ...AdminFilter::numberRange('rate'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('name', 'code', 'rate', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Tax $tax) => [
                    'id' => $tax->id,
                    'name' => $tax->name,
                    'code' => $tax->code,
                    'rate' => (string) $tax->rate,
                    'rate_label' => number_format((float) $tax->rate, 2).'%',
                    'is_active' => (bool) $tax->is_active,
                ]),
        ]);
    }

    public function store(TaxRequest $request): RedirectResponse
    {
        $this->taxService->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Tax created successfully'));
    }

    public function update(TaxRequest $request, int $tax): RedirectResponse
    {
        /** @var Tax $model */
        $model = $this->taxes->findOrFail($tax);

        $this->taxService->update($model, $request->validated());

        return back()->with('success', __('Tax updated successfully'));
    }

    public function active(Request $request, int $tax): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var Tax $model */
        $model = $this->taxes->findOrFail($tax);

        $this->taxService->setActive($model, (bool) $data['is_active']);

        return back()->with('success', __('Tax status updated successfully'));
    }

    public function destroy(int $tax): RedirectResponse
    {
        /** @var Tax $model */
        $model = $this->taxes->findOrFail($tax);

        if (! $this->taxService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Tax deleted successfully'));
    }

    public function info(int $tax): JsonResponse
    {
        return response()->json(RecordInfo::of($this->taxes->findOrFail($tax)));
    }

    protected function sortableModel(): string
    {
        return Tax::class;
    }
}
