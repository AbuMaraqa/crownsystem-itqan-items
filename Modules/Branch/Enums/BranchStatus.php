<?php

namespace Modules\Branch\Enums;
use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum BranchStatus: int implements HasLabel , HasColor
{
    case OPEN = 1;
    case CLOSED = 2;


    public function getLabel(): ?string
    {
        return match ($this) {
            self::OPEN => __('Open'),
            self::CLOSED => __('Closed'),
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::OPEN => 'success',
            self::CLOSED => 'danger',
        };
    }
}
