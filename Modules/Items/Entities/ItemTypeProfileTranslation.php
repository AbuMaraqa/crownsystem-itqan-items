<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;

class ItemTypeProfileTranslation extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'item_type_profile_translations');
    }

    protected $fillable = [
        'name',
        'locale',
    ];
}
