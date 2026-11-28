<?php

namespace Modules\Core\Support\Search;

/**
 * توحيد النص قبل الفهرسة والبحث، حتى يجد المستخدم الصنف مهما اختلفت كتابته:
 * الهمزات (أ إ آ ← ا)، التاء المربوطة (ة ← ه)، الالف المقصورة (ى ← ي)،
 * التشكيل والتطويل، الارقام الهندية، وحالة الحروف اللاتينية.
 *
 * كل تحويل هنا حرف بحرف (يُبدل الحرف او يُحذف)، فيمكن ردّ موضع اي حرف في
 * النص الموحَّد الى موضعه في النص الاصلي، وعليه يقوم تمييز المطابقة.
 */
final class TextNormalizer
{
    /**
     * اقصر كلمة يُنزع منها "ال" التعريف: يبقى بعدها حرفان على الاقل.
     */
    private const ARTICLE_MIN_LENGTH = 4;

    private const ARTICLE = 'ال';

    /**
     * @var array<string, string>|null
     */
    private static ?array $map = null;

    /**
     * النص موحَّداً: حروف وارقام فقط تفصل بينها مسافة واحدة.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_strtolower(strtr($text, self::map()), 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * كلمات النص الموحَّد بلا تكرار، بترتيب ورودها.
     *
     * @return array<int, string>
     */
    public static function words(?string $text): array
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * كلمات المستند في الفهرس: الكلمة نفسها، وصيغتها بلا "ال"، واجزاء الرموز
     * المختلطة (K4593 ← k و 4593) ليُعثر على الصنف بأي منها.
     *
     * @return array<int, string>
     */
    public static function indexWords(?string $text): array
    {
        $words = [];

        foreach (self::words($text) as $word) {
            $words[] = $word;

            if (($stripped = self::withoutArticle($word)) !== null) {
                $words[] = $stripped;
            }

            foreach (self::alphanumericParts($word) as $part) {
                $words[] = $part;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * الرمز (رقم صنف او باركود) ملتصقاً بلا فواصل: D-2000 ← d2000.
     */
    public static function compact(?string $text): string
    {
        return str_replace(' ', '', self::normalize($text));
    }

    /**
     * الكلمة بلا "ال" التعريف، او null اذا لم تبدأ بها او كانت اقصر من ذلك.
     */
    public static function withoutArticle(string $word): ?string
    {
        if (mb_strlen($word) < self::ARTICLE_MIN_LENGTH || ! str_starts_with($word, self::ARTICLE)) {
            return null;
        }

        return mb_substr($word, mb_strlen(self::ARTICLE));
    }

    /**
     * النص موحَّداً حرفاً حرفاً مع موضع كل حرف منه في النص الاصلي، دون طيّ
     * المسافات، لتمييز المطابقة في النص المعروض.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public static function normalizeWithOffsets(string $text): array
    {
        $normalized = '';
        $offsets = [];
        $position = 0;

        foreach (mb_str_split($text) as $index => $char) {
            $converted = mb_strtolower(strtr($char, self::map()), 'UTF-8');

            foreach (mb_str_split($converted) as $convertedChar) {
                $normalized .= preg_match('/[\p{L}\p{N}]/u', $convertedChar) ? $convertedChar : ' ';
                $offsets[$position++] = $index;
            }
        }

        return [$normalized, $offsets];
    }

    /**
     * @return array<int, string>
     */
    private static function alphanumericParts(string $word): array
    {
        if (! preg_match('/\p{L}/u', $word) || ! preg_match('/\p{N}/u', $word)) {
            return [];
        }

        return preg_split('/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [
            // الهمزات على الالف، وهمزة الوصل.
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            // الهمزة على الواو والياء.
            'ؤ' => 'و', 'ئ' => 'ي',
            'ة' => 'ه',
            'ى' => 'ي',
            // الكاف والياء الفارسيتان.
            'ک' => 'ك', 'ی' => 'ي',
            // التطويل والالف الخنجرية.
            'ـ' => '', "\u{0670}" => '',
        ];

        // التشكيل: من الفتحتين الى السكون وما يليه من علامات.
        foreach (range(0x064B, 0x065F) as $codePoint) {
            $map[mb_chr($codePoint, 'UTF-8')] = '';
        }

        // الارقام الهندية والفارسية.
        foreach (range(0, 9) as $digit) {
            $map[mb_chr(0x0660 + $digit, 'UTF-8')] = (string) $digit;
            $map[mb_chr(0x06F0 + $digit, 'UTF-8')] = (string) $digit;
        }

        return self::$map = $map;
    }
}
