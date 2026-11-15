<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Items\Entities\Product;

/**
 * فئتا المتابعة، ولكل فئة صلاحيتها ومستلمو اشعاراتها:
 * - الالمنيوم: اصناف الوزن (بروفيلات)، تُجمَّع حسب اللون.
 * - خارج الالمنيوم: اصناف الكمية (القطع والمستلزمات).
 */
enum StockFollowUpCategory: string implements HasLabel
{
    case ALUMINUM = 'aluminum';
    case PARTS = 'parts';

    public static function for(Product $product): self
    {
        return $product->pricing_type === 'weight' ? self::ALUMINUM : self::PARTS;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ALUMINUM => __('Aluminum'),
            self::PARTS => __('Non-Aluminum (Parts)'),
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::ALUMINUM => 'Aluminum Stock Follow-up View List',
            self::PARTS => 'Parts Stock Follow-up View List',
        };
    }

    public function route(): string
    {
        return 'accounting.inventory.follow-up.'.$this->value;
    }

    /**
     * تصفية اصناف الفئة في استعلام على جدول الاصناف (نفس قاعدة for()).
     */
    public function scope(Builder $query): Builder
    {
        $column = $query->qualifyColumn('pricing_type');

        return $this === self::ALUMINUM
            ? $query->where($column, 'weight')
            : $query->where(fn (Builder $q) => $q->where($column, '!=', 'weight')->orWhereNull($column));
    }
}
