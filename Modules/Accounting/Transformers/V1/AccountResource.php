<?php

namespace Modules\Accounting\Transformers\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class AccountResource extends JsonResource
{
    public static function allowedFields(): array
    {
        return [
            'id',
            'code',
            'parent_id',
            'type',
            'normal_balance',
            'level',
            'path',
            'sort_order',
            'is_postable',
            'is_active',
        ];
    }

    public static function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('id'),
            AllowedFilter::exact('code'),
            AllowedFilter::exact('parent_id'),
            AllowedFilter::exact('type'),
            AllowedFilter::exact('normal_balance'),
            AllowedFilter::exact('is_postable'),
            AllowedFilter::exact('is_active'),
            AllowedFilter::callback('name', fn ($query, $value) => $query->whereHas(
                'translations',
                fn ($q) => $q->where('name', 'like', "%{$value}%")
            )),
        ];
    }

    public static function allowedSorts(): array
    {
        return [
            AllowedSort::field('id'),
            AllowedSort::field('code'),
            AllowedSort::field('level'),
            AllowedSort::field('sort_order'),
            AllowedSort::field('created_at'),
            AllowedSort::callback('name', function ($query, $direction) {
                $query->orderByTranslation('name', $direction ? 'desc' : 'asc');
            }),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'type' => $this->type?->value(),
            'normal_balance' => $this->normal_balance?->value(),
            'level' => $this->level,
            'path' => $this->path,
            'sort_order' => $this->sort_order,
            'is_postable' => $this->is_postable,
            'is_active' => $this->is_active,
            'translations' => $this->whenLoaded('translations'),
            'children' => AccountResource::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
