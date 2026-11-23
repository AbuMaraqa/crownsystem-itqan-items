<?php

namespace Modules\Items\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\SqlDialect;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;

/**
 * ترقيم شجرة الاصناف كما في النظام القديم: لكل عقدة رقم، وابن العقدة رقمها
 * وبعده تسلسل من ثلاث خانات (00001 ← 00001001، 00001002 ...).
 *
 * رقم التصنيف (code) ورقم الصنف (sku) يتقاسمان الترقيم نفسه، لان العقدة في
 * النظام القديم قد تكون فئة او صنفاً. الارقام المحذوفة لا يُعاد استخدامها.
 */
class ItemCode
{
    public const CHILD_DIGITS = 3;

    private const DEFAULT_ROOT_DIGITS = 5;

    /**
     * الرقم التالي تحت رقم اب، او null اذا امتلأ التسلسل (999).
     */
    public static function nextChild(string $parentCode): ?string
    {
        $parentCode = trim($parentCode);

        if ($parentCode === '') {
            return null;
        }

        $length = mb_strlen($parentCode) + self::CHILD_DIGITS;
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $parentCode).'%';

        $codes = Product::withTrashed()
            ->where('sku', 'like', $prefix)
            ->whereRaw(SqlDialect::charLength('sku').' = ?', [$length])
            ->pluck('sku')
            ->merge(Category::withTrashed()
                ->where('code', 'like', $prefix)
                ->whereRaw(SqlDialect::charLength('code').' = ?', [$length])
                ->pluck('code'));

        $last = $codes
            ->map(fn (string $code): string => mb_substr($code, -self::CHILD_DIGITS))
            ->filter(fn (string $suffix): bool => ctype_digit($suffix))
            ->map(fn (string $suffix): int => (int) $suffix)
            ->max() ?? 0;

        return $last >= 999 ? null : $parentCode.str_pad((string) ($last + 1), self::CHILD_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * رقم تصنيف جذري جديد بطول ارقام الجذور الشائع (00001)، بعد اكبر رقم
     * مستخدم بهذا الطول في التصنيفات او الاصناف.
     */
    public static function nextRoot(): string
    {
        $categories = (new Category)->getTable();
        $products = (new Product)->getTable();

        $length = (int) (DB::table($categories)
            ->whereNull('parent_id')
            ->whereRaw(SqlDialect::regexp('code'), ['^[0-9]+$'])
            ->selectRaw(SqlDialect::charLength('code').' as code_length, COUNT(*) as total')
            ->groupBy('code_length')
            ->orderByDesc('total')
            ->orderByDesc('code_length')
            ->value('code_length') ?: self::DEFAULT_ROOT_DIGITS);

        $pattern = '^[0-9]{'.$length.'}$';

        $last = max(
            (int) DB::table($categories)->whereRaw(SqlDialect::regexp('code'), [$pattern])->max(DB::raw(SqlDialect::castInteger('code'))),
            (int) DB::table($products)->whereRaw(SqlDialect::regexp('sku'), [$pattern])->max(DB::raw(SqlDialect::castInteger('sku'))),
        );

        return str_pad((string) ($last + 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * رقم تصنيف جديد تحت ابيه، او جذري بلا اب. null اذا لم يكن للاب رقم.
     */
    public static function forCategory(?int $parentId): ?string
    {
        if (! $parentId) {
            return self::nextRoot();
        }

        $parentCode = Category::withTrashed()->whereKey($parentId)->value('code');

        return filled($parentCode) ? self::nextChild($parentCode) : null;
    }

    /**
     * رقم اللون التالي بنمط النظام القديم: تسلسل بثلاث خانات (001، 002 ...) بعد اكبر رقم مستخدم،
     * ولو لون محذوف، فلا يُعاد رقم محذوف. الرمز الذي يكتبه المستخدم بغير ارقام لا يدخل الحساب.
     */
    public static function nextFinish(): string
    {
        $last = Finish::withTrashed()
            ->pluck('code')
            ->filter(fn (?string $code): bool => $code !== null && ctype_digit($code))
            ->map(fn (string $code): int => (int) $code)
            ->max() ?? 0;

        return str_pad((string) ($last + 1), self::CHILD_DIGITS, '0', STR_PAD_LEFT);
    }
}
