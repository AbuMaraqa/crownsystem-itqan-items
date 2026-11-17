<?php

namespace Modules\Core\Contracts;

/**
 * قيمة (غالباً enum) لها لون: اسم نغمة مثل success / danger / warning / info / gray / primary.
 * يحوّلها StatusBadge::tone() الى نغمة الشارة في اللوحة.
 */
interface HasColor
{
    public function getColor(): string|array|null;
}
