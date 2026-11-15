<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\GeoLocation\Entities\City;
use Modules\GeoLocation\Entities\District;
use Modules\Items\Entities\Order;
use Modules\Items\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Items\Support\ProductSearch;

/**
 * قراءات الطلبات (طلبات المتجر) في لوحة Vue.
 */
class OrderRepository extends BaseEloquentRepository implements OrderRepositoryInterface
{
    public function __construct(Order $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['user.media', 'items.product.translations']);
    }

    public function forForm(int $id): Order
    {
        /** @var Order */
        return $this->query()->with(['user', 'items.product.translations'])->findOrFail($id);
    }

    public function lookup(string $type, string $search): array
    {
        $search = trim($search);

        if ($type === 'products') {
            return $this->pairs(ProductSearch::options($search, 25));
        }

        if ($type === 'customers') {
            return $this->pairs(User::query()
                ->when($search !== '', fn (Builder $query) => $query->whereLike('name', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')
                ->limit(50)
                ->pluck('name', 'id')
                ->all());
        }

        $model = $type === 'cities' ? City::class : District::class;

        return $this->pairs($model::query()
            ->with('translations')
            ->when($search !== '', fn (Builder $query) => $query->whereTranslationLike('name', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Model $place): array => [$place->getKey() => (string) $place->name])
            ->all());
    }

    public function labels(string $type, array $ids): array
    {
        $ids = array_values(array_filter($ids));

        if ($ids === []) {
            return [];
        }

        return $this->pairs(match ($type) {
            'products' => ProductSearch::labels($ids),
            'customers' => User::query()->whereKey($ids)->pluck('name', 'id')->all(),
            'cities' => City::query()->with('translations')->whereKey($ids)->get()->mapWithKeys(fn (City $city): array => [$city->id => (string) $city->name])->all(),
            default => District::query()->with('translations')->whereKey($ids)->get()->mapWithKeys(fn (District $district): array => [$district->id => (string) $district->name])->all(),
        });
    }

    /**
     * @param  array<int|string, string|null>  $map
     * @return list<array{value: int, label: string}>
     */
    private function pairs(array $map): array
    {
        $pairs = [];

        foreach ($map as $id => $label) {
            $pairs[] = ['value' => (int) $id, 'label' => (string) $label];
        }

        return $pairs;
    }
}
