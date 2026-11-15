<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Attribute;

interface AttributeRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin attributes table: every attribute with its translations (the table orders it).
     */
    public function tableQuery(): Builder;

    /**
     * An attribute with what its edit form shows: translations and its values in their order.
     */
    public function findForForm(int $id): Attribute;
}
