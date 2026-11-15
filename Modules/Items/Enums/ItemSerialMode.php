<?php

namespace Modules\Items\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * سيريال الصنف: سيريال لكل قطعة، او سيريال بكمية.
 */
enum ItemSerialMode: string implements HasLabel
{
    case NONE = 'none';
    case SERIAL = 'serial';
    case SERIAL_WITH_QUANTITY = 'serial_with_quantity';

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
            self::SERIAL => __('Serial'),
            self::SERIAL_WITH_QUANTITY => __('Serial with Quantity'),
        };
    }
}
