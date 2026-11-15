<?php

namespace Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Entities\StockAlert;
use Modules\Accounting\Entities\StockLimitChange;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\StockAlertLevel;
use Modules\Accounting\Enums\StockFollowUpCategory;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Support\StockLimits;
use Modules\Items\Entities\Product;

/**
 * قراءات قسم متابعة المخزون: اصناف القسم مجمّعة، اقتراحا كمية الطلب، والرصيد يوماً بيوم.
 *
 * الايام بتوقيت الشركة المحلي (accounting.company_timezone)، لا بتوقيت الخادم.
 *
 * اقتراحا الطلب (لا يُنشأ طلب تلقائياً):
 * 1. حسب الحد الاعلى = الحد الاعلى − الكمية الحالية.
 * 2. حسب سحب آخر 6 اشهر = المسحوب خلال آخر 6 اشهر − الكمية الحالية.
 *    المسحوب = حركات الاخراج للبيع ناقص المرتجعات، دون تعديلات الجرد ولا التحويل بين المخازن.
 *    صنف حركاته اقل من 6 اشهر: متوسط السحب الشهري منذ اول حركة × 6.
 */
class StockFollowUpService
{
    public const ORDER = 'order';

    public const NONE = 'none';

    public const NO_DATA = 'no_data';

    public const USAGE_MONTHS = 6;

    /**
     * التحويل بين المخازن لا يغيّر رصيد الصنف الكلي، فلا يظهر في حركته اليومية.
     */
    private const INTERNAL_TYPES = [StockMovementType::TRANSFER_IN, StockMovementType::TRANSFER_OUT];

    public function timezone(): string
    {
        return config('accounting.company_timezone', 'Asia/Hebron');
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * فترة الجدول اليومي في نافذة الصنف: آخر 30 يوماً افتراضياً، تاريخان معكوسان يُبدَّلان،
     * والفترة لا تتجاوز سنة.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function ledgerRange(?string $from, ?string $to): array
    {
        $tz = $this->timezone();
        $end = rescue(fn () => filled($to) ? CarbonImmutable::parse($to, $tz)->startOfDay() : $this->today(), $this->today(), false);
        $start = rescue(fn () => filled($from) ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays(29), $end->subDays(29), false);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > 366) {
            $start = $end->subDays(366);
        }

        return ['from' => $start, 'to' => $end];
    }

    /**
     * «اسم الصنف - اللون» كما يظهر في الاشعار والقسم.
     */
    public function itemLabel(Product $product): string
    {
        $color = $product->effective_color;

        return trim($product->name.($color ? ' - '.$color : ''));
    }

    // ---------------------------------------------------------------- القسم

    /**
     * اصناف القسم لفئة واحدة، الاخطر اولاً. الالمنيوم مجموعة لكل لون، والقطع مجموعة واحدة.
     *
     * @return Collection<int, array{color: ?string, rows: Collection<int, array>}>
     */
    public function groups(StockFollowUpCategory $category, string $search = ''): Collection
    {
        $alerts = StockAlert::query()
            ->with(['product.translations', 'product.mainProduct.translations', 'product.unit.translations'])
            ->whereHas('product', function (Builder $query) use ($category, $search) {
                $category->scope($query);

                if (trim($search) !== '') {
                    $term = '%'.trim($search).'%';
                    $query->where(fn (Builder $q) => $q->where('barcode', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhereTranslationLike('name', $term));
                }
            })
            ->get();

        $products = $alerts->pluck('product');
        $suggestions = $this->suggestionsFor($products);

        $rows = $alerts->map(fn (StockAlert $alert) => [
            'alert' => $alert,
            'product' => $alert->product,
            'color' => $category === StockFollowUpCategory::ALUMINUM ? $alert->product->effective_color : null,
            'suggestions' => $suggestions[$alert->product_id],
        ]);

        $sortRows = fn (Collection $rows) => $rows
            ->sortBy([
                fn (array $a, array $b) => $b['alert']->level->severity() <=> $a['alert']->level->severity(),
                fn (array $a, array $b) => strcmp((string) $a['product']->name, (string) $b['product']->name),
            ])
            ->values();

        return $rows
            ->groupBy(fn (array $row) => (string) $row['color'])
            ->map(fn (Collection $rows, string $color) => [
                'color' => $color !== '' ? $color : null,
                'severity' => $rows->max(fn (array $row) => $row['alert']->level->severity()),
                'rows' => $sortRows($rows),
            ])
            ->sortBy([
                fn (array $a, array $b) => $b['severity'] <=> $a['severity'],
                fn (array $a, array $b) => strcmp((string) $a['color'], (string) $b['color']),
            ])
            ->values();
    }

    /**
     * عدد اصناف القسم في كل حالة لفئة واحدة.
     *
     * @return array<string, int>
     */
    public function counts(StockFollowUpCategory $category): array
    {
        return StockAlert::query()
            ->whereHas('product', fn (Builder $query) => $category->scope($query))
            ->selectRaw('level, COUNT(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    // ---------------------------------------------------------------- اقتراح الطلب

    /**
     * @return array{by_max: array, by_usage: array}|null  null للصنف غير المراقَب
     */
    public function suggestions(Product $product, ?float $quantity = null): ?array
    {
        if ($quantity !== null) {
            $product = (clone $product)->setAttribute('stock_quantity', $quantity);
        }

        return $this->suggestionsFor(collect([$product]))[$product->id];
    }

    /**
     * اقتراحات عدة اصناف باستعلامين فقط (المسحوب، واول حركة).
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array{by_max: array, by_usage: array}|null>
     */
    public function suggestionsFor(Collection $products): array
    {
        $today = $this->today();
        $from = $today->subMonthsNoOverflow(self::USAGE_MONTHS)->addDay();
        $ids = $products->pluck('id')->all();
        $withdrawn = $this->withdrawals($ids, $from, $today);
        $firstDates = $ids === [] ? collect() : StockMovement::query()
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, MIN(movement_date) as first_date')
            ->pluck('first_date', 'product_id');

        $result = [];

        foreach ($products as $product) {
            if (! StockLimits::isMonitored($product)) {
                $result[$product->id] = null;

                continue;
            }

            $current = (float) $product->stock_quantity;
            $byMax = round((float) $product->max_limit - $current, 4);
            $result[$product->id] = [
                'by_max' => [
                    'state' => $byMax > 0 ? self::ORDER : self::NONE,
                    'quantity' => $byMax,
                    'max' => (float) $product->max_limit,
                    'current' => $current,
                ],
                'by_usage' => $this->usageSuggestion(
                    max((float) ($withdrawn[$product->id] ?? 0), 0),
                    isset($firstDates[$product->id]) ? CarbonImmutable::parse($firstDates[$product->id], $this->timezone()) : null,
                    $from,
                    $today,
                    $current,
                ),
            ];
        }

        return $result;
    }

    private function usageSuggestion(float $withdrawn, ?CarbonImmutable $firstDate, CarbonImmutable $from, CarbonImmutable $today, float $current): array
    {
        if ($withdrawn <= 0) {
            return ['state' => self::NO_DATA, 'quantity' => null, 'withdrawn' => 0.0, 'projected' => 0.0, 'months' => null, 'current' => $current];
        }

        $projected = $withdrawn;
        $months = null;

        // اقل من 6 اشهر من الحركات: متوسط شهري منذ اول حركة × 6 (شهر واحد على الاقل).
        if ($firstDate && $firstDate->greaterThan($from)) {
            $months = max(((int) abs($firstDate->diffInDays($today)) + 1) / 30.4375, 1);
            $projected = round($withdrawn / $months * self::USAGE_MONTHS, 4);
        }

        $quantity = round($projected - $current, 4);

        return [
            'state' => $quantity > 0 ? self::ORDER : self::NONE,
            'quantity' => $quantity,
            'withdrawn' => $withdrawn,
            'projected' => $projected,
            'months' => $months !== null ? round($months, 1) : null,
            'current' => $current,
        ];
    }

    /**
     * المسحوب لكل صنف بين تاريخين: الاخراج للبيع (سند تسليم او فاتورة) ناقص مرتجعات البيع،
     * مع عكس حركات المستندات الملغاة. تعديلات الجرد والتحويل لا تدخل.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, float>
     */
    public function withdrawals(array $productIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        $salesSources = [(new DeliveryNote)->getMorphClass(), (new SalesInvoice)->getMorphClass(), (new SalesReturn)->getMorphClass()];

        return StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn (Builder $q) => $q
                ->whereIn('type', [StockMovementType::DELIVERY->value, StockMovementType::SALES_RETURN->value])
                ->orWhere(fn (Builder $q) => $q->where('type', StockMovementType::REVERSAL->value)->whereIn('sourceable_type', $salesSources)))
            ->groupBy('product_id')
            ->selectRaw('product_id, -SUM(quantity) as withdrawn')
            ->pluck('withdrawn', 'product_id')
            ->map(fn ($value) => (float) $value);
    }

    // ---------------------------------------------------------------- تفاصيل الصنف

    /**
     * سطر لكل يوم: رصيد اول اليوم + الوارد − الصادر = رصيد آخر اليوم، وحالة الصنف بنهايته
     * (بحدوده الحالية). الاحدث اولاً.
     *
     * @return array{opening: float, closing: float, incoming: float, outgoing: float, days: array<int, array>}
     */
    public function dailyLedger(Product $product, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opening = (float) $this->movementsQuery($product)->where('movement_date', '<', $from->toDateString())->sum('quantity');

        $totals = $this->movementsQuery($product)
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('movement_date')
            ->selectRaw('movement_date as day, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as incoming, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as outgoing, COUNT(*) as movements')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->day, 0, 10));

        $days = [];
        $balance = $opening;
        $sumIn = 0.0;
        $sumOut = 0.0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $row = $totals[$day->toDateString()] ?? null;
            $incoming = round((float) ($row->incoming ?? 0), 4);
            $outgoing = round((float) ($row->outgoing ?? 0), 4);
            $closing = round($balance + $incoming - $outgoing, 4);

            $days[] = [
                'date' => $day->toDateString(),
                'opening' => $balance,
                'incoming' => $incoming,
                'outgoing' => $outgoing,
                'closing' => $closing,
                'movements' => (int) ($row->movements ?? 0),
                'level' => StockAlertLevel::for($product, $closing),
                'monitored' => StockLimits::isMonitored($product),
            ];

            $balance = $closing;
            $sumIn += $incoming;
            $sumOut += $outgoing;
        }

        return [
            'opening' => $opening,
            'closing' => $balance,
            'incoming' => round($sumIn, 4),
            'outgoing' => round($sumOut, 4),
            'days' => array_reverse($days),
        ];
    }

    /**
     * حركات يوم واحد مع الرصيد بعد كل حركة. مجموعها = وارد اليوم − صادره في الجدول اليومي.
     *
     * @return Collection<int, StockMovement>
     */
    public function dayMovements(Product $product, CarbonImmutable $day): Collection
    {
        $balance = (float) $this->movementsQuery($product)->where('movement_date', '<', $day->toDateString())->sum('quantity');

        return $this->movementsQuery($product)
            ->with(['createdBy.media', 'warehouse.translations'])
            ->whereDate('movement_date', $day->toDateString())
            ->orderBy('id')
            ->get()
            ->each(function (StockMovement $movement) use (&$balance) {
                $balance = round($balance + (float) $movement->quantity, 4);
                $movement->balance_after = $balance;
                $movement->local_time = $movement->created_at?->copy()->setTimezone($this->timezone())->format('H:i');
                $movement->source_url = $movement->sourceUrl();
            });
    }

    /**
     * @return Collection<int, StockLimitChange>
     */
    public function limitHistory(Product $product, int $limit = 10): Collection
    {
        return StockLimitChange::query()
            ->with('changedBy.media')
            ->where('product_id', $product->id)
            ->latest('id')
            ->take($limit)
            ->get();
    }

    private function movementsQuery(Product $product): Builder
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->whereNotIn('type', array_map(fn (StockMovementType $type) => $type->value, self::INTERNAL_TYPES));
    }
}
