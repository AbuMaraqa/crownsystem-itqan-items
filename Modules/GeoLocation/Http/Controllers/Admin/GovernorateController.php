<?php

namespace Modules\GeoLocation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\GeoLocation\Entities\Governorate;
use Modules\GeoLocation\Http\Requests\Admin\GovernorateRequest;
use Modules\GeoLocation\Repositories\Contracts\CountryRepositoryInterface;
use Modules\GeoLocation\Repositories\Contracts\GovernorateRepositoryInterface;
use Modules\GeoLocation\Services\GovernorateService;
use Modules\GeoLocation\Support\GeoFilters;

class GovernorateController extends Controller
{
    use ReordersRecords;

    public function __construct(
        private readonly GovernorateRepositoryInterface $governorates,
        private readonly GovernorateService $governorateService,
        private readonly CountryRepositoryInterface $countries,
    ) {}

    public function index(): Response
    {
        return Inertia::render('GeoLocation/Governorate/Index', [
            'table' => AdminTable::for($this->governorates->tableQuery())
                ->search(['code'])
                ->filters(
                    AdminFilter::translated('name'),
                    AdminFilter::exact('code'),
                    AdminFilter::relation('country_id', 'country'),
                    GeoFilters::locale(),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts(GeoFilters::nameSort(), 'code', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Governorate $governorate) => [
                    'id' => $governorate->id,
                    'name' => $governorate->name,
                    'code' => $governorate->code,
                    'country' => $governorate->country?->name,
                    'locales' => $governorate->translations->pluck('locale'),
                ]),
            'codes' => $this->governorates->codes(),
            'countries' => $this->countries->options(),
            'locales' => Locales::keys(),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('GeoLocation/Governorate/Add', [
            'countries' => $this->countries->options(),
        ]);
    }

    public function store(GovernorateRequest $request): RedirectResponse
    {
        $this->governorateService->create($request->validated(), app()->getLocale());

        return to_route('governorates.index')->with('success', __('Governorate created successfully'));
    }

    public function edit(int $governorate): Response
    {
        /** @var Governorate $model */
        $model = $this->governorates->findOrFail($governorate);

        return Inertia::render('GeoLocation/Governorate/Edit', [
            'governorate' => [
                'id' => $model->id,
                'name' => $model->translate(app()->getLocale(), false)?->name ?? '',
                'code' => $model->code,
                'country_id' => $model->country_id,
            ],
            'countries' => $this->countries->options(),
        ]);
    }

    public function update(GovernorateRequest $request, int $governorate): RedirectResponse
    {
        /** @var Governorate $model */
        $model = $this->governorates->findOrFail($governorate);

        $this->governorateService->update($model, $request->validated(), app()->getLocale());

        return to_route('governorates.index')->with('success', __('Governorate updated successfully'));
    }

    public function destroy(int $governorate): RedirectResponse
    {
        /** @var Governorate $model */
        $model = $this->governorates->findOrFail($governorate);

        $this->governorateService->delete($model);

        return back()->with('success', __('Governorate deleted successfully'));
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        $this->governorateService->deleteMany($ids);

        return back()->with('success', __('Governorates deleted successfully'));
    }

    public function info(int $governorate): JsonResponse
    {
        return response()->json(RecordInfo::of($this->governorates->findOrFail($governorate)));
    }

    protected function sortableModel(): string
    {
        return Governorate::class;
    }
}
