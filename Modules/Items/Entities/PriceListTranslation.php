<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;

class PriceListTranslation extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'price_list_translations');
    }

    protected $fillable = [
        'name',
        'locale',
    ];
}
