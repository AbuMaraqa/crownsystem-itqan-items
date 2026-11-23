<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Entities\Finish;
use Modules\Items\Http\Requests\Admin\FinishRequest;
use Modules\Items\Repositories\Contracts\FinishRepositoryInterface;
use Modules\Items\Services\FinishService;
use Modules\Items\Support\ItemCode;

/**
 * صفحة «الألوان»: القائمة الواحدة لألوان كل الماركات، ومنها تُكتب سمة «اللون» التي يقرأها المتجر.
 */
class FinishController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly FinishRepositoryInterface $finishes,
        private readonly FinishService $finishService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Finish/Index', [
            'table' => AdminTable::for($this->finishes->tableQuery())
                ->search(['name', 'code'], null)
                ->filters(AdminFilter::text('name'), AdminFilter::boolean('is_active'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'code', 'sort_order', 'category_finishes_count', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Finish $finish) => [
                    'id' => $finish->id,
                    'name' => $finish->name,
                    'code' => $finish->code,
                    'color_code' => $finish->attributeValue?->color_code,
                    'is_active' => (bool) $finish->is_active,
                    'is_system' => (bool) $finish->is_system,
                    'categories_count' => (int) $finish->category_finishes_count,
                ]),
            // رقم اللون التالي بنمط النظام القديم، يظهر في نموذج الاضافة ويمكن تغييره.
            'nextCode' => ItemCode::nextFinish(),
        ]);
    }

    public function store(FinishRequest $request): RedirectResponse
    {
        $this->finishService->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Colour saved.'));
    }

    public function update(FinishRequest $request, int $finish): RedirectResponse
    {
        try {
            $this->finishService->update($this->find($finish), $request->validated());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Colour saved.'));
    }

    public function destroy(int $finish): RedirectResponse
    {
        try {
            $this->finishService->delete($this->find($finish));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Colour deleted.'));
    }

    public function info(int $finish): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($finish)));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->finishes->tableQuery();
    }

    /**
     * @param  Finish  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        try {
            $this->finishService->delete($record);
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    protected function sortableModel(): string
    {
        return Finish::class;
    }

    private function find(int $id): Finish
    {
        /** @var Finish */
        return $this->finishes->findOrFail($id);
    }
}
