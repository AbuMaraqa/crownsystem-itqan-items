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
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Enums\ItemBonusMode;
use Modules\Items\Enums\ItemDateMode;
use Modules\Items\Enums\ItemSerialMode;
use Modules\Items\Enums\ItemSubItemMode;
use Modules\Items\Http\Requests\Admin\ItemTypeProfileRequest;
use Modules\Items\Repositories\Contracts\ItemTypeProfileRepositoryInterface;
use Modules\Items\Services\ItemTypeProfileService;
use Modules\Core\Support\RecordInfo;

/**
 * انواع الاصناف: مصفوفة الخصائص التي تحدد شكل سطر الصنف. الاضافة والتعديل في نافذة
 * على صفحة القائمة.
 */
class ItemTypeProfileController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly ItemTypeProfileRepositoryInterface $profiles,
        private readonly ItemTypeProfileService $profileService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/ItemTypeProfile/Index', [
            'table' => AdminTable::for($this->profiles->tableQuery())
                ->search(['code'])
                ->filters(
                    AdminFilter::boolean('is_active'),
                    AdminFilter::exact('sub_item_mode'),
                    AdminFilter::exact('serial_mode'),
                    AdminFilter::exact('date_mode'),
                    AdminFilter::exact('bonus_mode'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('code', 'sort_order', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (ItemTypeProfile $profile) => [
                    'id' => $profile->id,
                    'code' => $profile->code,
                    'name' => (string) $profile->name,
                    'sort_order' => (int) $profile->sort_order,
                    'quantity_decimals' => $profile->quantity_decimals,
                    'price_decimals' => $profile->price_decimals,
                    'notes_count' => (int) $profile->notes_count,
                    'date_mode' => $profile->date_mode?->value,
                    'bonus_mode' => $profile->bonus_mode?->value,
                    'serial_mode' => $profile->serial_mode?->value,
                    'sub_item_mode' => $profile->sub_item_mode?->value,
                    'dimensions' => (int) $profile->dimensions,
                    'has_color' => (bool) $profile->has_color,
                    'has_size' => (bool) $profile->has_size,
                    'is_active' => (bool) $profile->is_active,
                ]),
            'modes' => [
                'date' => $this->options(ItemDateMode::options()),
                'bonus' => $this->options(ItemBonusMode::options()),
                'serial' => $this->options(ItemSerialMode::options()),
                'subItem' => $this->options(ItemSubItemMode::options()),
            ],
        ]);
    }

    public function store(ItemTypeProfileRequest $request): RedirectResponse
    {
        $this->profileService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Item type saved successfully'));
    }

    public function update(ItemTypeProfileRequest $request, int $profile): RedirectResponse
    {
        /** @var ItemTypeProfile $model */
        $model = $this->profiles->findOrFail($profile);

        $this->profileService->update($model, $request->validated(), app()->getLocale());

        return back()->with('success', __('Item type saved successfully'));
    }

    public function destroy(int $profile): RedirectResponse
    {
        /** @var ItemTypeProfile $model */
        $model = $this->profiles->findOrFail($profile);

        try {
            if (! $this->profileService->delete($model)) {
                return back()->with('error', SafeDelete::message());
            }
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Item type deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->profiles->tableQuery();
    }

    /**
     * @param  ItemTypeProfile  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->profileService->delete($record);
    }

    protected function sortableModel(): string
    {
        return ItemTypeProfile::class;
    }

    public function info(int $profile): JsonResponse
    {
        return response()->json(RecordInfo::of($this->profiles->findOrFail($profile)));
    }

    /**
     * @param  array<string, string>  $options  value => label
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
