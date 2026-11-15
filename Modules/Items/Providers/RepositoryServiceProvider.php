<?php

namespace Modules\Items\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Items\Repositories\AddonRepository;
use Modules\Items\Repositories\AttributeRepository;
use Modules\Items\Repositories\BrandRepository;
use Modules\Items\Repositories\CategoryRepository;
use Modules\Items\Repositories\Contracts\AddonRepositoryInterface;
use Modules\Items\Repositories\Contracts\AttributeRepositoryInterface;
use Modules\Items\Repositories\Contracts\BrandRepositoryInterface;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Items\Repositories\Contracts\ItemTypeProfileRepositoryInterface;
use Modules\Items\Repositories\Contracts\LabelRepositoryInterface;
use Modules\Items\Repositories\Contracts\ManufacturerRepositoryInterface;
use Modules\Items\Repositories\Contracts\OfferRepositoryInterface;
use Modules\Items\Repositories\Contracts\PriceListRepositoryInterface;
use Modules\Items\Repositories\Contracts\TagRepositoryInterface;
use Modules\Items\Repositories\Contracts\UnitRepositoryInterface;
use Modules\Items\Repositories\ItemTypeProfileRepository;
use Modules\Items\Repositories\LabelRepository;
use Modules\Items\Repositories\ManufacturerRepository;
use Modules\Items\Repositories\OfferRepository;
use Modules\Items\Repositories\PriceListRepository;
use Modules\Items\Repositories\TagRepository;
use Modules\Items\Repositories\UnitRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AddonRepositoryInterface::class => AddonRepository::class,
        AttributeRepositoryInterface::class => AttributeRepository::class,
        BrandRepositoryInterface::class => BrandRepository::class,
        CategoryRepositoryInterface::class => CategoryRepository::class,
        ItemTypeProfileRepositoryInterface::class => ItemTypeProfileRepository::class,
        LabelRepositoryInterface::class => LabelRepository::class,
        ManufacturerRepositoryInterface::class => ManufacturerRepository::class,
        OfferRepositoryInterface::class => OfferRepository::class,
        PriceListRepositoryInterface::class => PriceListRepository::class,
        TagRepositoryInterface::class => TagRepository::class,
        UnitRepositoryInterface::class => UnitRepository::class,
        \Modules\Items\Repositories\Contracts\ProductRepositoryInterface::class => \Modules\Items\Repositories\ProductRepository::class,
        \Modules\Items\Repositories\Contracts\ItemTreeRepositoryInterface::class => \Modules\Items\Repositories\ItemTreeRepository::class,
        \Modules\Items\Repositories\Contracts\OrderRepositoryInterface::class => \Modules\Items\Repositories\OrderRepository::class,
    ];
}
