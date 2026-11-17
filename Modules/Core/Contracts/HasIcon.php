<?php

namespace Modules\Core\Contracts;

/**
 * قيمة (غالباً enum) لها اسم أيقونة.
 */
interface HasIcon
{
    public function getIcon(): ?string;
}
