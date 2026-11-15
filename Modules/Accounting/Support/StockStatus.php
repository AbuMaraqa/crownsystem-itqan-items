<?php

namespace Modules\Accounting\Support;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Items\Entities\Product;

/**
 * حالة رصيد الصنف من حدوده في بطاقته (كما في النظام القديم):
 * الحد الاعلى، الحد الادنى الاول (وقت الطلب)، الحد الادنى الثاني (حرج).
 */
enum StockStatus: string implements HasLabel
{
    case OUT = 'out';
    case CRITICAL = 'critical';
    case LOW = 'low';
    case OK = 'ok';
    case OVER = 'over';

    public static function for(Product $product, float $quantity): self
    {
        $min1 = (float) $product->min_limit_1;
        $min2 = (float) $product->min_limit_2;
        $max = (float) $product->max_limit;

        return match (true) {
            $quantity <= 0 => self::OUT,
            $min2 > 0 && $quantity <= $min2 => self::CRITICAL,
            $min1 > 0 && $quantity <= $min1 => self::LOW,
            $max > 0 && $quantity > $max => self::OVER,
            default => self::OK,
        };
    }

    /**
     * خيارات الفلتر بالوصف الكامل.
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->put('reorder', __('Needs Reorder (below Min Limit 1)'))
            ->all();
    }

    /**
     * تسمية قصيرة للشارة في الجداول.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::OUT => __('Out of Stock'),
            self::CRITICAL => __('Critical'),
            self::LOW => __('Low'),
            self::OK => __('Normal'),
            self::OVER => __('Above Max'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OUT => __('Out of Stock'),
            self::CRITICAL => __('Critical (below Min Limit 2)'),
            self::LOW => __('Low (below Min Limit 1)'),
            self::OK => __('Normal'),
            self::OVER => __('Above Max Limit'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OUT, self::CRITICAL => 'danger',
            self::LOW => 'warning',
            self::OK => 'success',
            self::OVER => 'info',
        };
    }

    /**
     * فلتر الحالة في SQL. الكمية = رصيد الصنف المجمّع (balances.quantity).
     */
    public static function applyFilter(Builder $query, ?string $status): Builder
    {
        if (blank($status)) {
            return $query;
        }

        $quantity = 'COALESCE(balances.quantity, 0)';
        $min1 = 'COALESCE('.$query->qualifyColumn('min_limit_1').', 0)';
        $min2 = 'COALESCE('.$query->qualifyColumn('min_limit_2').', 0)';
        $max = 'COALESCE('.$query->qualifyColumn('max_limit').', 0)';

        return match ($status) {
            self::OUT->value => $query->whereRaw("{$quantity} <= 0"),
            self::CRITICAL->value => $query->whereRaw("{$quantity} > 0 AND {$min2} > 0 AND {$quantity} <= {$min2}"),
            self::LOW->value => $query->whereRaw("{$quantity} > 0 AND {$min1} > 0 AND {$quantity} <= {$min1} AND NOT ({$min2} > 0 AND {$quantity} <= {$min2})"),
            self::OVER->value => $query->whereRaw("{$max} > 0 AND {$quantity} > {$max}"),
            self::OK->value => $query->whereRaw("{$quantity} > 0 AND NOT ({$min1} > 0 AND {$quantity} <= {$min1}) AND NOT ({$min2} > 0 AND {$quantity} <= {$min2}) AND NOT ({$max} > 0 AND {$quantity} > {$max})"),
            'reorder' => $query->whereRaw("{$min1} > 0 AND {$quantity} <= {$min1}"),
            default => $query,
        };
    }
}
