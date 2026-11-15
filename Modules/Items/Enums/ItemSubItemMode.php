<?php

namespace Modules\Items\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * تفاصيل تحت سطر الصنف: مقاطع الالمنيوم، او مكونات الصنف التجميعي.
 */
enum ItemSubItemMode: string implements HasLabel
{
    case NONE = 'none';
    case ALUMINIUM = 'aluminium';
    case ASSEMBLY = 'assembly';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => __('None'),
            self::ALUMINIUM => __('Aluminium'),
            self::ASSEMBLY => __('Assembly'),
        };
    }
}
