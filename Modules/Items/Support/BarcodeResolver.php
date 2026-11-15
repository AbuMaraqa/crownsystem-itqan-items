<?php

namespace Modules\Items\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;

/**
 * قراءة الباركود. الباركود الواحد قد يكون لعدة اصناف (مقطع يتكرر تحت كل لون)، فلا
 * يُختار احدها بصمت: resolveAll يعيد كل المطابقات بالاولوية (باركود الصنف الرئيسي، ثم
 * الباركودات الاضافية، ثم البادئات من الاطول)، و resolve يعيد مطابقة فقط اذا كان
 * الصنف واحداً. الصنف المحذوف لا يطابق.
 */
final class BarcodeResolver
{
    /**
     * المطابقة الاعلى اولوية اذا طابق صنف واحد فقط، والا null (لا شيء، او اكثر من صنف).
     */
    public static function resolve(string $code): ?ResolvedBarcode
    {
        $matches = self::resolveAll($code);
        $productIds = array_unique(array_map(fn (ResolvedBarcode $match): int => (int) $match->product->id, $matches));

        return count($productIds) === 1 ? $matches[0] : null;
    }

    /**
     * @return array<int, ResolvedBarcode>
     */
    public static function resolveAll(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return [];
        }

        $matches = Product::query()->with('unit')->where('barcode', $code)->orderBy('id')->get()
            ->map(fn (Product $product): ResolvedBarcode => new ResolvedBarcode($product, $product->unit, null, '1.000000', 'primary'))
            ->all();

        $exact = self::barcodes()->where('barcode', $code)->get();

        foreach ($exact as $row) {
            $matches[] = self::fromRow($row, 'exact');
        }

        // البادئات من النص نفسه تبحث في فهرس الباركود، بدل LIKE يمر على كل الصفوف.
        $prefixes = self::barcodes()
            ->where('is_prefix', true)
            ->whereIn('barcode', self::prefixesOf($code))
            ->whereNotIn('id', $exact->modelKeys())
            ->get()
            ->sortByDesc(fn (ProductBarcode $candidate): int => mb_strlen($candidate->barcode));

        foreach ($prefixes as $row) {
            $matches[] = self::fromRow($row, 'prefix');
        }

        return $matches;
    }

    private static function barcodes(): Builder
    {
        return ProductBarcode::query()
            ->with(['product.unit', 'productUnit.unit'])
            // whereHas يمر بنطاق الحذف الناعم لجدول الاصناف.
            ->whereHas('product')
            ->orderBy('product_id');
    }

    private static function fromRow(ProductBarcode $row, string $matchedBy): ResolvedBarcode
    {
        return new ResolvedBarcode(
            $row->product,
            $row->productUnit?->unit ?? $row->product->unit,
            $row->productUnit,
            (string) $row->pack_quantity,
            $matchedBy,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function prefixesOf(string $code): array
    {
        $prefixes = [];

        for ($length = min(mb_strlen($code), 255); $length > 0; $length--) {
            $prefixes[] = mb_substr($code, 0, $length);
        }

        return $prefixes;
    }
}
