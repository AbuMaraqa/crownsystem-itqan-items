<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\StockAlert;
use Modules\Accounting\Enums\StockAlertLevel;
use Modules\Accounting\Enums\StockFollowUpCategory;
use Modules\Accounting\Support\Quantity;
use Modules\Core\Entities\User;
use Modules\Core\Services\NotificationService;
use Modules\Items\Entities\Product;
use Throwable;

/**
 * تنبيهات المخزون: يقارن رصيد الصنف بحدوده الثلاثة بعد كل حركة مخزون وبعد تعديل حدوده.
 *
 * - الصنف عند الحد الادنى 1 او اقل يدخل قسم المتابعة (صف في stock_alerts) او يبقى فيه.
 * - الاشعار عند نزوله الى حالة اخطر فقط (تنبيه ← يجب الطلب ← نفد)؛ لا يتكرر مع كل حركة،
 *   والقفز عبر حدين بحركة واحدة يعطي اشعاراً واحداً بالحالة النهائية. التحسّن لا يرسل اشعاراً.
 * - ارتفاعه فوق الحد الادنى 1 او ايقاف مراقبته يخرجه من القسم، وتُعلَّم اشعاراته «تمت المعالجة».
 *
 * يُستدعى داخل معاملة حركة المخزون نفسها، فالحركة والرصيد والحالة والاشعار تنجح معاً او تفشل معاً.
 */
class StockAlertService
{
    public const EVENT = 'stock_alert';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly StockFollowUpService $followUp,
    ) {}

    private static int $batchDepth = 0;

    /** @var array<int, bool> */
    private static array $pending = [];

    /**
     * عملية تُخرج الصنف ثم تعيده (تحويل بين مخازن، عكس مستند) تُقيَّم مرة واحدة في نهايتها،
     * فلا يصدر تنبيه كاذب من رصيد مؤقت. التقييم يبقى داخل معاملة العملية نفسها.
     */
    public function batch(callable $callback): mixed
    {
        self::$batchDepth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if (--self::$batchDepth === 0) {
                self::$pending = [];
            }

            throw $e;
        }

        if (--self::$batchDepth === 0) {
            $pending = array_keys(self::$pending);
            self::$pending = [];

            foreach ($pending as $productId) {
                $this->evaluate($productId);
            }
        }

        return $result;
    }

    public function evaluate(int $productId, bool $notify = true): ?StockAlertLevel
    {
        if (self::$batchDepth > 0 && $notify) {
            self::$pending[$productId] = true;

            return null;
        }

        return DB::transaction(function () use ($productId, $notify): ?StockAlertLevel {
            // قفل الصنف يمنع حسابين متزامنين لنفس الصنف من ادخاله القسم مرتين.
            $product = Product::query()->with(['translations'])->lockForUpdate()->find($productId);

            if (! $product) {
                StockAlert::query()->where('product_id', $productId)->delete();

                return null;
            }

            $current = StockAlert::query()->where('product_id', $productId)->first();
            $quantity = (float) $product->stock_quantity;
            $level = $this->isTracked($product) ? StockAlertLevel::for($product, $quantity) : null;

            if ($level === null) {
                if ($current) {
                    $current->delete();
                    $this->notifications->resolve(self::EVENT, $product);
                }

                return null;
            }

            $worse = ! $current || $level->severity() > $current->level->severity();

            if (! $current) {
                StockAlert::query()->create([
                    'product_id' => $productId,
                    'level' => $level,
                    'quantity' => $quantity,
                    'entered_at' => now(),
                    'level_changed_at' => now(),
                ]);
            } else {
                $current->fill(['quantity' => $quantity]);

                if ($current->level !== $level) {
                    $current->fill(['level' => $level, 'level_changed_at' => now()]);
                }

                $current->save();
            }

            if ($worse && $notify) {
                $this->notify($product, $level, $quantity);
            }

            return $level;
        });
    }

    /**
     * يعيد حساب حالة كل الاصناف المراقَبة (بعد النشر او للتصحيح)، ويخرج من القسم ما لم يعد مراقَباً.
     */
    public function rebuild(bool $notify = false): int
    {
        $count = 0;

        Product::query()
            ->where(fn ($q) => $q->where('max_limit', '>', 0)->orWhere('min_limit_1', '>', 0)->orWhere('min_limit_2', '>', 0))
            ->select('id')
            ->chunkById(500, function (Collection $products) use (&$count, $notify) {
                foreach ($products as $product) {
                    $this->evaluate($product->id, $notify);
                    $count++;
                }
            });

        // اصناف بقيت في القسم وقد صُفّرت حدودها او حُذفت.
        StockAlert::query()->pluck('product_id')->each(fn (int $productId) => $this->evaluate($productId, $notify));

        return $count;
    }

    /**
     * الصنف الاب وعاء لألوانه (يُتابَع كل لون وحده)، والخدمات لا مخزون لها.
     */
    private function isTracked(Product $product): bool
    {
        return ! $product->isService()
            && ! Product::query()->where('parent_id', $product->id)->exists();
    }

    private function notify(Product $product, StockAlertLevel $level, float $quantity): void
    {
        $category = StockFollowUpCategory::for($product);
        $suggestions = $this->followUp->suggestions($product, $quantity);

        $message = match ($level) {
            StockAlertLevel::WARNING => ':item reached Min Limit 1. Quantity :quantity (Max :max, Min 1 :min1, Min 2 :min2). Suggested order: :by_max to reach the max, or :by_usage by the last 6 months withdrawals.',
            StockAlertLevel::REORDER => ':item reached Min Limit 2 and must be ordered. Quantity :quantity (Max :max, Min 1 :min1, Min 2 :min2). Suggested order: :by_max to reach the max, or :by_usage by the last 6 months withdrawals.',
            StockAlertLevel::OUT => ':item is out of stock. Quantity :quantity (Max :max, Min 1 :min1, Min 2 :min2). Suggested order: :by_max to reach the max, or :by_usage by the last 6 months withdrawals.',
        };

        $this->notifications->send(
            recipients: $this->recipients($category),
            title: ':level: :item',
            message: $message,
            params: [
                'level' => NotificationService::enum($level),
                'item' => $this->followUp->itemLabel($product),
                'quantity' => Quantity::format($quantity),
                'max' => Quantity::format($product->max_limit),
                'min1' => Quantity::format($product->min_limit_1),
                'min2' => Quantity::format($product->min_limit_2),
                'by_max' => $this->suggestionParam($suggestions['by_max']),
                'by_usage' => $this->suggestionParam($suggestions['by_usage']),
            ],
            url: route($category->route(), ['item' => $product->id]),
            subject: $product,
            event: self::EVENT,
            icon: 'solar-box-bold-duotone',
            color: $level->notificationColor(),
            extra: ['level' => $level->value, 'category' => $category->value],
            skipSender: false,
        );
    }

    private function suggestionParam(array $suggestion): string|array
    {
        return match ($suggestion['state']) {
            StockFollowUpService::ORDER => Quantity::format($suggestion['quantity']),
            StockFollowUpService::NO_DATA => NotificationService::trans('no withdrawal data'),
            default => NotificationService::trans('no order needed'),
        };
    }

    /**
     * المستلمون: من يملك صلاحية متابعة فئة الصنف.
     *
     * @return Collection<int, User>
     */
    private function recipients(StockFollowUpCategory $category): Collection
    {
        try {
            return User::query()->permission($category->permission())->get();
        } catch (Throwable) {
            // الصلاحية لم تُنشأ بعد (قبل الترحيل): لا مستلمين.
            return collect();
        }
    }
}
