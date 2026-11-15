<?php

namespace Modules\Items\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * نوع الخدمة يحدد حسابي ايرادها وتكلفتها الافتراضيين: خدمات النقل لها حساب
 * ايراد نقل، وتكلفتها مصروف توصيل؛ وباقي الخدمات ايراد خدمات وتكلفة خدمات.
 */
enum ServiceKind: string implements HasLabel
{
    case GENERAL = 'general';
    case TRANSPORT = 'transport';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::GENERAL => __('General Service'),
            self::TRANSPORT => __('Transport Service'),
        };
    }
}
