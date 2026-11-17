<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * تاريخ سطر الصنف: دفعات بتاريخ يُختار منها عند البيع، او تاريخ يُدخل بحرية.
 */
enum ItemDateMode: string implements HasLabel
{
    case NONE = 'none';
    case LOT_DATE = 'lot_date';
    case OPEN_DATE = 'open_date';

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
            self::LOT_DATE => __('Dated Lots'),
            self::OPEN_DATE => __('Free Date'),
        };
    }
}
