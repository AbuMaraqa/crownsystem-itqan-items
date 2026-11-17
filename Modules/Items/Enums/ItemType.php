<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * طبيعة الصنف: سلعة تُباع من البضاعة، او خدمة تُقدَّم للعميل (معالجة، نقل ...)
 * لا تدخل المخزون، ولها ايراد وتكلفة مستقلان عن البضاعة.
 */
enum ItemType: string implements HasLabel
{
    case GOODS = 'goods';
    case SERVICE = 'service';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::GOODS => __('Goods'),
            self::SERVICE => __('Service'),
        };
    }
}
