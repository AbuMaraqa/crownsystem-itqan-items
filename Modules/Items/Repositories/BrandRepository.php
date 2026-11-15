<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Product;
use Modules\Items\Repositories\Contracts\BrandRepositoryInterface;

class BrandRepository extends BaseEloquentRepository implements BrandRepositoryInterface
{
    public function __construct(Brand $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['translations', 'media']);
    }

    public function isUsed(Brand $brand): bool
    {
        return Product::withTrashed()->where('brand_id', $brand->id)->exists();
    }
}
