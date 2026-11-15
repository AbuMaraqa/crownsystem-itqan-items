<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Addon;

interface AddonRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin addons table: every addon with its translations and image (the table orders it).
     */
    public function tableQuery(): Builder;

    /**
     * An addon with what its edit form shows: translations, and its options with theirs and their images.
     */
    public function findForForm(int $id): Addon;
}
