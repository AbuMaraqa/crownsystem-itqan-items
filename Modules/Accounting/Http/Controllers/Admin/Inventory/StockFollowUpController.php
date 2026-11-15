<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\StockAlert;
use Modules\Accounting\Entities\StockLimitChange;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\StockAlertLevel;
use Modules\Accounting\Enums\StockFollowUpCategory;
use Modules\Accounting\Services\StockFollowUpService;
use Modules\Accounting\Support\Quantity;
use Modules\Accounting\Support\StatusBadge;
use Modules\Accounting\Support\StockLimits;
use Modules\Core\Support\UserCellData;
use Modules\Items\Entities\Product;

/**
 * قسم متابعة المخزون: الاصناف التي وصل رصيدها الى الحد الادنى 1 او اقل، الاخطر اولاً. لكل فئة
 * رابط وصلاحية (الالمنيوم مجمّع حسب اللون، والقطع في تبويب مستقل)، ونافذة تفاصيل الصنف
 * (?item=): الرصيد والحركات يوماً بيوم، واقتراحا الطلب، وتعديل الحدود وسجلها.
 */
class StockFollowUpController extends Controller
{
    public function __construct(private readonly StockFollowUpService $service) {}

    public function index(Request $request, string $category): Response
    {
        $category = StockFollowUpCategory::from($category);
        $search = $request->string('q')->toString();
        $user = $request->user();
        $fmt = fn ($value): string => Quantity::format($value);
        $tz = $this->service->timezone();
        $counts = $this->service->counts($category);

        return Inertia::render('Accounting/Inventory/StockFollowUp/Index', [
            'category' => $category->value,
            'categoryLabel' => $category->getLabel(),
            'isAluminum' => $category === StockFollowUpCategory::ALUMINUM,
            'search' => $search,
            'tabs' => collect(StockFollowUpCategory::cases())
                ->filter(fn (StockFollowUpCategory $case) => $user->can($case->permission()))
                ->map(fn (StockFollowUpCategory $case) => [
                    'value' => $case->value,
                    'label' => $case->getLabel(),
                    'href' => route($case->route()),
                    'count' => array_sum($this->service->counts($case)),
                ])
                ->values()
                ->all(),
            'counts' => collect(array_reverse(StockAlertLevel::cases()))
                ->map(fn (StockAlertLevel $level) => ['badge' => StatusBadge::of($level), 'count' => $counts[$level->value] ?? 0])
                ->all(),
            'groups' => $this->service->groups($category, $search)->map(fn (array $group) => [
                'color' => $group['color'],
                'rows' => $group['rows']->map(fn (array $row) => [
                    'id' => $row['product']->id,
                    'alert_id' => $row['alert']->id,
                    'name' => $row['product']->name,
                    'code' => $row['product']->barcode ?: $row['product']->sku,
                    'main_product' => $row['product']->mainProduct?->name,
                    'quantity' => $fmt($row['product']->stock_quantity),
                    'min_limit_1' => $fmt($row['product']->min_limit_1),
                    'min_limit_2' => $fmt($row['product']->min_limit_2),
                    'max_limit' => $fmt($row['product']->max_limit),
                    'level' => StatusBadge::of($row['alert']->level),
                    'by_max' => $this->suggestion($row['suggestions']['by_max'] ?? null),
                    'by_usage' => $this->suggestion($row['suggestions']['by_usage'] ?? null),
                    'since' => $row['alert']->entered_at?->setTimezone($tz)->format('d/m/Y H:i'),
                ])->values()->all(),
            ])->values()->all(),
            'details' => $request->integer('item') ? $this->details($category, $request) : null,
            'canEditLimits' => StockLimits::canEdit(),
        ]);
    }

    /**
     * حدود الصنف من نافذته (Max > Min 1 > Min 2، او كلها صفر لايقاف المراقبة). السجل واعادة
     * حساب الحالة في StockLimitObserver عند الحفظ.
     */
    public function saveLimits(Request $request, int $product): RedirectResponse
    {
        // الفئة من defaults() على المسار؛ المعاملات تُمرَّر بالترتيب فلا تُقرأ كمعامل للدالة هنا.
        $category = StockFollowUpCategory::from((string) $request->route('category'));
        abort_unless($request->user()->can($category->permission()), 403);

        $record = $this->findProduct($category, $product);
        abort_if(! $record, 404);

        $data = $request->validate([
            'max_limit' => ['nullable', 'numeric'],
            'min_limit_1' => ['nullable', 'numeric'],
            'min_limit_2' => ['nullable', 'numeric'],
        ]);
        $errors = StockLimits::errors($data['max_limit'] ?? null, $data['min_limit_1'] ?? null, $data['min_limit_2'] ?? null);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $record->update(collect(StockLimits::FIELDS)->mapWithKeys(fn (string $field) => [$field => (float) ($data[$field] ?? 0)])->all());

        return back()->with('success', StockLimits::isMonitored($record)
            ? __('Stock limits saved.')
            : __('Stock limits cleared; the item is no longer monitored.'));
    }

    /**
     * يُفتح فقط صنف من فئة الصفحة، فلا يرى صاحب صلاحية فئة اصناف الفئة الاخرى عبر الرابط.
     */
    private function findProduct(StockFollowUpCategory $category, int $productId): ?Product
    {
        return $category->scope(Product::query())
            ->with(['translations', 'mainProduct.translations', 'unit.translations'])
            ->find($productId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function details(StockFollowUpCategory $category, Request $request): ?array
    {
        $product = $this->findProduct($category, $request->integer('item'));

        if (! $product) {
            return null;
        }

        $fmt = fn ($value): string => Quantity::format($value);
        $tz = $this->service->timezone();
        ['from' => $from, 'to' => $to] = $this->service->ledgerRange($request->string('from')->toString() ?: null, $request->string('to')->toString() ?: null);
        $quantity = (float) $product->stock_quantity;
        $monitored = StockLimits::isMonitored($product);
        $level = StockAlertLevel::for($product, $quantity);
        $alert = StockAlert::query()->where('product_id', $product->id)->first();
        $suggestions = $this->service->suggestions($product);
        $ledger = $this->service->dailyLedger($product, $from, $to);
        $day = $request->string('day')->toString() ?: null;
        $normal = ['label' => __('Normal'), 'tone' => 'success'];

        return [
            'id' => $product->id,
            'label' => $this->service->itemLabel($product),
            'code' => $product->barcode ?: $product->sku,
            'main_product' => $product->mainProduct?->name,
            'since' => $alert?->entered_at?->setTimezone($tz)->format('d/m/Y H:i'),
            'quantity' => $fmt($quantity),
            'max_limit' => $fmt($product->max_limit),
            'min_limit_1' => $fmt($product->min_limit_1),
            'min_limit_2' => $fmt($product->min_limit_2),
            'limits' => collect(StockLimits::FIELDS)->mapWithKeys(fn (string $field) => [$field => (float) $product->{$field} ?: 0])->all(),
            'status' => ! $monitored ? ['label' => __('Unmonitored'), 'tone' => 'neutral'] : ($level ? StatusBadge::of($level) : $normal),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'day' => $day,
            'ledger' => [
                'opening' => $fmt($ledger['opening']),
                'incoming' => $fmt($ledger['incoming']),
                'outgoing' => $fmt($ledger['outgoing']),
                'closing' => $fmt($ledger['closing']),
                'days' => array_map(fn (array $row) => [
                    'date' => $row['date'],
                    'label' => CarbonImmutable::parse($row['date'])->format('d/m/Y'),
                    'opening' => $fmt($row['opening']),
                    'incoming' => $row['incoming'] > 0 ? $fmt($row['incoming']) : null,
                    'outgoing' => $row['outgoing'] > 0 ? $fmt($row['outgoing']) : null,
                    'closing' => $fmt($row['closing']),
                    'movements' => $row['movements'],
                    'status' => ! $row['monitored'] ? null : ($row['level'] ? StatusBadge::of($row['level']) : $normal),
                ], $ledger['days']),
            ],
            'movements' => $day ? $this->service->dayMovements($product, CarbonImmutable::parse($day, $tz))->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'time' => $movement->local_time,
                'type' => StatusBadge::of($movement->type),
                'quantity' => ((float) $movement->quantity > 0 ? '+' : '').$fmt($movement->quantity),
                'incoming' => (float) $movement->quantity >= 0,
                'balance' => $fmt($movement->balance_after),
                'user' => UserCellData::of($movement->createdBy),
                'reference_no' => $movement->reference_no,
                'url' => $movement->source_url,
                'description' => $movement->description,
                'warehouse' => $movement->warehouse?->name,
            ])->values()->all() : null,
            'suggestions' => $suggestions ? [
                'by_max' => [...$this->suggestion($suggestions['by_max']), 'max' => $fmt($suggestions['by_max']['max']), 'current' => $fmt($suggestions['by_max']['current']), 'value' => $fmt($suggestions['by_max']['quantity'])],
                'by_usage' => [
                    ...$this->suggestion($suggestions['by_usage']),
                    'has_data' => $suggestions['by_usage']['state'] !== StockFollowUpService::NO_DATA,
                    'months' => $suggestions['by_usage']['months'] ?? null,
                    'withdrawn' => $fmt($suggestions['by_usage']['withdrawn'] ?? 0),
                    'projected' => $fmt($suggestions['by_usage']['projected'] ?? 0),
                    'current' => $fmt($suggestions['by_usage']['current'] ?? 0),
                    'value' => $fmt($suggestions['by_usage']['quantity'] ?? 0),
                ],
            ] : null,
            'history' => $this->service->limitHistory($product)->map(fn (StockLimitChange $change) => [
                'id' => $change->id,
                'user' => UserCellData::of($change->changedBy),
                'at' => $change->created_at?->setTimezone($tz)->format('d/m/Y H:i'),
                'old' => implode(' / ', [$fmt($change->old_max_limit), $fmt($change->old_min_limit_1), $fmt($change->old_min_limit_2)]),
                'new' => implode(' / ', [$fmt($change->new_max_limit), $fmt($change->new_min_limit_1), $fmt($change->new_min_limit_2)]),
            ])->values()->all(),
        ];
    }

    /**
     * قيمة اقتراح طلب واحد: الكمية المقترحة، او «لا حاجة للطلب»، او «لا توجد بيانات سحب».
     *
     * @param  array<string, mixed>|null  $suggestion
     * @return array{state: string, quantity: ?string}
     */
    private function suggestion(?array $suggestion): array
    {
        if (! $suggestion) {
            return ['state' => 'none_shown', 'quantity' => null];
        }

        return [
            'state' => (string) $suggestion['state'],
            'quantity' => $suggestion['state'] === StockFollowUpService::ORDER ? Quantity::format($suggestion['quantity']) : null,
        ];
    }
}
