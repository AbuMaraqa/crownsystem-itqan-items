<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\AddonOption;
use Modules\Items\Enums\AddonType;
use Modules\Items\Http\Requests\Admin\AddonRequest;
use Modules\Items\Repositories\Contracts\AddonRepositoryInterface;
use Modules\Items\Services\AddonService;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class AddonController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly AddonRepositoryInterface $addons,
        private readonly AddonService $addonService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Addon/Index', [
            'table' => AdminTable::for($this->addons->tableQuery())
                ->search()
                ->filters(AdminFilter::translated('name'), AdminFilter::exact('type'), ...AdminFilter::dateRange('created_at'))
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'type', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Addon $addon) => [
                    'id' => $addon->id,
                    'image' => $addon->getImageAttribute(),
                    'name' => (string) $addon->name,
                    'description' => $addon->description,
                    'type' => $addon->type?->getLabel(),
                    'locales' => $addon->translations->pluck('locale'),
                ]),
            'types' => $this->types(),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Items/Addon/Add', ['types' => $this->types()]);
    }

    public function store(AddonRequest $request): RedirectResponse
    {
        $this->addonService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return to_route('addons.index')->with('success', __('Addon added successfully'));
    }

    public function edit(int $addon): Response
    {
        $model = $this->addons->findForForm($addon);
        $locale = app()->getLocale();

        return Inertia::render('Items/Addon/Edit', [
            'addon' => [
                'id' => $model->id,
                'name' => $model->translate($locale, false)?->name ?? '',
                'type' => $model->type?->value,
                'description' => $model->translate($locale, false)?->description ?? '',
                'options' => $model->addonOptions->map(function (AddonOption $option) use ($locale) {
                    // An option not translated yet opens with its first translation, to edit from.
                    $translation = $option->translate($locale, false) ?? $option->translations->first();
                    $media = $option->getFirstMedia('addon_option');

                    return [
                        'id' => $option->id,
                        'name' => $translation?->name ?? '',
                        'description' => $translation?->description ?? '',
                        'price' => $option->price,
                        'is_default' => (bool) $option->is_default,
                        'is_quantifiable' => (bool) $option->is_quantifiable,
                        'images' => $media ? [['id' => $media->id, 'url' => $media->getUrl()]] : [],
                    ];
                })->values(),
            ],
            'types' => $this->types(),
        ]);
    }

    public function update(AddonRequest $request, int $addon): RedirectResponse
    {
        /** @var Addon $model */
        $model = $this->addons->findOrFail($addon);

        $this->addonService->update($model, $request->validated(), app()->getLocale());

        return to_route('addons.index')->with('success', __('Addon updated successfully'));
    }

    public function destroy(int $addon): RedirectResponse
    {
        /** @var Addon $model */
        $model = $this->addons->findOrFail($addon);

        if (! $this->addonService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Addon deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->addons->tableQuery();
    }

    /**
     * @param  Addon  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->addonService->delete($record);
    }

    protected function sortableModel(): string
    {
        return Addon::class;
    }

    public function info(int $addon): JsonResponse
    {
        return response()->json(RecordInfo::of($this->addons->findOrFail($addon)));
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function types(): array
    {
        return array_map(
            fn (AddonType $type) => ['value' => $type->value, 'label' => $type->getLabel()],
            AddonType::cases(),
        );
    }
}
