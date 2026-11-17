<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * سماكة مقطع الالمنيوم.
 */
enum ProfileThickness: string implements HasLabel
{
    case THIN = 'thin';
    case THICK = 'thick';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::THIN => __('Thin'),
            self::THICK => __('Thick'),
        };
    }
}
