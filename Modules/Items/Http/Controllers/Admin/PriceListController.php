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
use Modules\Core\Support\SafeDelete;
use Modules\Items\Entities\PriceList;
use Modules\Items\Http\Requests\Admin\PriceListRequest;
use Modules\Items\Repositories\Contracts\PriceListRepositoryInterface;
use Modules\Items\Services\PriceListService;
use Modules\Core\Support\RecordInfo;

/**
 * قوائم الاسعار: الاضافة والتعديل في نافذة على صفحة القائمة. اسعار كل صنف تُدخل من
 * صفحة وحدات الصنف واسعاره.
 */
class PriceListController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly PriceListRepositoryInterface $lists,
        private readonly PriceListService $priceListService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/PriceList/Index', [
            'table' => AdminTable::for($this->lists->tableQuery())
                ->search()
                ->filters(
                    AdminFilter::boolean('is_active'),
                    AdminFilter::boolean('same_buy_sell'),
                    ...AdminFilter::numberRange('sort_order'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('sort_order', 'prices_count', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (PriceList $list) => [
                    'id' => $list->id,
                    'name' => (string) $list->name,
                    'same_buy_sell' => (bool) $list->same_buy_sell,
                    'prices_count' => (int) $list->prices_count,
                    'sort_order' => (int) $list->sort_order,
                    'is_active' => (bool) $list->is_active,
                ]),
        ]);
    }

    public function store(PriceListRequest $request): RedirectResponse
    {
        $this->priceListService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Price list saved successfully'));
    }

    public function update(PriceListRequest $request, int $priceList): RedirectResponse
    {
        /** @var PriceList $model */
        $model = $this->lists->findOrFail($priceList);

        $this->priceListService->update($model, $request->validated(), app()->getLocale());

        return back()->with('success', __('Price list saved successfully'));
    }

    public function destroy(int $priceList): RedirectResponse
    {
        /** @var PriceList $model */
        $model = $this->lists->findOrFail($priceList);

        try {
            if (! $this->priceListService->delete($model)) {
                return back()->with('error', SafeDelete::message());
            }
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Price list deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->lists->tableQuery();
    }

    /**
     * @param  PriceList  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->priceListService->delete($record);
    }

    protected function sortableModel(): string
    {
        return PriceList::class;
    }

    public function info(int $priceList): JsonResponse
    {
        return response()->json(RecordInfo::of($this->lists->findOrFail($priceList)));
    }
}
