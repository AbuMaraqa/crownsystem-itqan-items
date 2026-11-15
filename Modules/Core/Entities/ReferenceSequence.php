<?php

namespace Modules\Core\Entities;

use Illuminate\Database\Eloquent\Model;

class ReferenceSequence extends Model
{
    protected $fillable = [
        'sequence_code',
        'prefix',
        'period_key',
        'scope_key',
        'last_number',
        'padding',
        'metadata',
    ];

    protected $casts = [
        'last_number' => 'integer',
        'padding' => 'integer',
        'metadata' => 'array',
    ];
}
