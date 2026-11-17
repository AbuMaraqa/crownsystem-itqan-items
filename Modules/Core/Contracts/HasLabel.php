<?php

namespace Modules\Core\Contracts;

/**
 * قيمة (غالباً enum) لها نص معروض مترجم.
 */
interface HasLabel
{
    public function getLabel(): ?string;
}
