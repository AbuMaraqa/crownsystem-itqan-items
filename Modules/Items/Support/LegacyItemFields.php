<?php

namespace Modules\Items\Support;

/**
 * معنى حقول Prop في جدول items القديم بحسب نوع الصنف، مصدر واحد للاستيراد
 * (LegacyItemsImporter) ولتصحيح ما استُورد سابقاً (LegacyFieldsBackfill).
 *
 * الانواع التي لم يتأكد معنى حقولها لا تُملأ لها اعمدة، وتبقى قيمها في meta_data.
 */
final class LegacyItemFields
{
    public const BARCODE_SEPARATOR = '█';

    /** a_store واشباهه: Prop1 هو الرف، واللواحق الاخرى بخيارات. */
    public const STORE_LIKE_TYPES = ['a_store', 'a_storeNote', 'a_storeDate', 'a_SerialNumber'];

    public const DIMENSION_TYPES = ['a_storeArea', 'a_storeVol'];

    /** خيار a_storePicture_AS_a_al مفعّل في النظام الحي: حقوله كحقول المقطع، ونوع تسعيره لا يتغير. */
    public const PICTURE_TYPE = 'a_storePicture';

    private const SHELF_MAX_LENGTH = 50;

    private const BARCODE_MAX_LENGTH = 255;

    private const NAME_MAX_LENGTH = 255;

    /** حد عمودي weight_per_meter و length: decimal(10,4). */
    private const MEASURE_LIMIT = 1000000;

    /**
     * اعمدة الصنف المستنتجة من حقول Prop. لا يرجع مفتاحاً لقيمة فارغة او غير صالحة.
     *
     * @param  array<string, mixed>  $props  بمفاتيح Prop1..Prop5 كما في العمود القديم وفي meta_data.props
     * @return array<string, int|string>
     */
    public static function columns(?string $type, array $props): array
    {
        $columns = match (true) {
            $type === 'b_al', $type === self::PICTURE_TYPE => [
                'weight_per_meter' => self::measure($props['Prop1'] ?? null),
                'length' => self::measure($props['Prop2'] ?? null),
                'shelf' => self::shelf($props['Prop3'] ?? null),
            ],
            $type === 'a_al', in_array($type, self::STORE_LIKE_TYPES, true) => [
                'shelf' => self::shelf($props['Prop1'] ?? null),
            ],
            in_array($type, self::DIMENSION_TYPES, true) => [
                'shelf' => self::shelf($props['Prop1'] ?? null),
                'length' => self::measure($props['Prop2'] ?? null),
            ],
            default => [],
        };

        return array_filter($columns, fn ($value): bool => $value !== null);
    }

    /**
     * هل Prop1 في هذا الصف ليس وزناً بأي معنى؟ فقيمته في عمود weight خطأ استيراد.
     * b_al و a_storePicture مستثنيان لان Prop1 فيهما وزن فعلاً (وزن المتر)، وa_withUnit لان
     * Prop1 فيه الوزن، وغيرها غير مؤكد، فيبقى عمود weight كما هو.
     */
    public static function prop1IsNotWeight(?string $type): bool
    {
        return $type === 'a_al'
            || in_array($type, self::STORE_LIKE_TYPES, true)
            || in_array($type, self::DIMENSION_TYPES, true);
    }

    /**
     * الاسم الانجليزي من Prop6. في بعض الصفوف رقم لا اسم، فلا يصير ترجمة.
     *
     * @param  array<string, mixed>  $props
     */
    public static function englishName(array $props): ?string
    {
        $name = trim((string) ($props['Prop6'] ?? ''));

        return $name === '' || is_numeric($name) ? null : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * فصل باركودات الصنف القديم. اول باركود عادي هو الباركود الرئيسي، والباقي اضافية
     * بكمية العبوة من الموضع نفسه في Prop5. الباركود الذي يبدأ بنجمة باركود بادئة، فلا
     * يكون رئيسياً. sort_order هو موضعه الاصلي في النص، وموضع الرئيسي يبقى خالياً منه.
     *
     * في b_al قد يكون Prop5 قائمة موازية للباركودات وقد يكون رقماً واحداً لم يُحسم معناه،
     * فيُقرأ كميات فقط اذا انقسم الى عدد اجزاء الباركودات، وكان اكثر من جزء.
     *
     * @param  array<string, mixed>  $props
     * @return array{primary: string|null, primary_pack_quantity: string, secondary: array<int, array{barcode: string, pack_quantity: string, is_prefix: bool, sort_order: int}>}
     */
    public static function barcodes(?string $barcode, ?string $type, array $props): array
    {
        $segments = explode(self::BARCODE_SEPARATOR, (string) $barcode);
        $packs = explode(self::BARCODE_SEPARATOR, (string) ($props['Prop5'] ?? ''));

        if ($type === 'b_al' && (count($segments) < 2 || count($packs) !== count($segments))) {
            $packs = [];
        }

        $primary = null;
        $primaryPack = '1.000000';
        $secondary = [];
        $seen = [];

        foreach ($segments as $position => $segment) {
            $segment = trim($segment);
            $isPrefix = str_starts_with($segment, '*');
            $code = mb_substr(ltrim($segment, '*'), 0, self::BARCODE_MAX_LENGTH);
            $key = mb_strtolower($code);

            if ($code === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pack = self::packQuantity($packs[$position] ?? null);

            if ($primary === null && ! $isPrefix) {
                $primary = $code;
                $primaryPack = $pack;

                continue;
            }

            $secondary[] = ['barcode' => $code, 'pack_quantity' => $pack, 'is_prefix' => $isPrefix, 'sort_order' => $position];
        }

        return ['primary' => $primary, 'primary_pack_quantity' => $primaryPack, 'secondary' => $secondary];
    }

    private static function measure(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (! is_numeric($value)) {
            return null;
        }

        // الاعمدة بأربع منازل: وزن المتر 0.444 لا يصبح 0.44. الصفر (0.00) يعني غير محدد،
        // وما يتجاوز العمود يبقى في meta_data، فلا يُسقط صف واحد الاستيراد كله.
        $measure = round((float) $value, 4);

        return $measure > 0 && $measure < self::MEASURE_LIMIT ? number_format($measure, 4, '.', '') : null;
    }

    private static function shelf(mixed $value): ?string
    {
        $value = trim((string) $value);

        // الصفر يعني بلا رف، ولا يصير رفاً اسمه 0.
        if ($value === '' || (is_numeric($value) && (float) $value == 0.0)) {
            return null;
        }

        return mb_substr($value, 0, self::SHELF_MAX_LENGTH);
    }

    private static function packQuantity(mixed $value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) && (float) $value > 0 ? number_format((float) $value, 6, '.', '') : '1.000000';
    }
}
