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
use Modules\Items\Entities\Label;
use Modules\Items\Http\Requests\Admin\LabelRequest;
use Modules\Items\Repositories\Contracts\LabelRepositoryInterface;
use Modules\Items\Services\LabelService;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class LabelController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly LabelRepositoryInterface $labels,
        private readonly LabelService $labelService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Label/Index', [
            'table' => AdminTable::for($this->labels->tableQuery())
                ->search(['slug'])
                ->filters(AdminFilter::translated('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'slug', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Label $label) => [
                    'id' => $label->id,
                    'name' => (string) $label->name,
                    'slug' => $label->slug,
                    'description' => $label->description,
                    'text_color' => $label->text_color,
                    'background_color' => $label->background_color,
                    'icon' => $label->icon,
                    'locales' => $label->translations->pluck('locale'),
                    // The modal edits the current locale only, without the fallback the table shows.
                    'form' => [
                        'name' => $label->translate(app()->getLocale(), false)?->name ?? '',
                        'description' => $label->translate(app()->getLocale(), false)?->description ?? '',
                    ],
                ]),
        ]);
    }

    public function store(LabelRequest $request): RedirectResponse
    {
        $this->labelService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Label created successfully'));
    }

    public function update(LabelRequest $request, int $label): RedirectResponse
    {
        /** @var Label $model */
        $model = $this->labels->findOrFail($label);

        $this->labelService->update($model, $request->validated(), app()->getLocale());

        return back()->with('success', __('Label updated successfully'));
    }

    public function destroy(int $label): RedirectResponse
    {
        /** @var Label $model */
        $model = $this->labels->findOrFail($label);

        if (! $this->labelService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Label deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->labels->tableQuery();
    }

    /**
     * @param  Label  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->labelService->delete($record);
    }

    protected function sortableModel(): string
    {
        return Label::class;
    }

    public function info(int $label): JsonResponse
    {
        return response()->json(RecordInfo::of($this->labels->findOrFail($label)));
    }
}
