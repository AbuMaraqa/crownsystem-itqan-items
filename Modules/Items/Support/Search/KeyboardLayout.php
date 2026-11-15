<?php

namespace Modules\Items\Support\Search;

/**
 * تصحيح لغة لوحة المفاتيح: من يكتب "hglkdon" وهو يقصد "المنيوم" نسي تبديل
 * اللغة. تُحوَّل الحروف بين اللاتينية والعربية حسب مواضع المفاتيح في لوحة
 * المفاتيح العربية القياسية (101).
 */
final class KeyboardLayout
{
    /**
     * @var array<string, string>
     */
    private const LATIN_TO_ARABIC = [
        'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف', 'y' => 'غ', 'u' => 'ع', 'i' => 'ه', 'o' => 'خ', 'p' => 'ح', '[' => 'ج', ']' => 'د',
        'a' => 'ش', 's' => 'س', 'd' => 'ي', 'f' => 'ب', 'g' => 'ل', 'h' => 'ا', 'j' => 'ت', 'k' => 'ن', 'l' => 'م', ';' => 'ك', "'" => 'ط',
        'z' => 'ئ', 'x' => 'ء', 'c' => 'ؤ', 'v' => 'ر', 'b' => 'لا', 'n' => 'ى', 'm' => 'ة', ',' => 'و', '.' => 'ز', '/' => 'ظ', '`' => 'ذ',
    ];

    /**
     * النص مكتوباً باللغة الاخرى، او null اذا لم يكن فيه ما يُحوَّل، او كان
     * خليطاً من الحرفين فلا يُعرف المقصود.
     */
    public static function swap(string $text): ?string
    {
        $hasLatin = (bool) preg_match('/[a-z]/i', $text);
        $hasArabic = (bool) preg_match('/\p{Arabic}/u', $text);

        if ($hasLatin === $hasArabic) {
            return null;
        }

        $swapped = $hasLatin
            ? strtr(mb_strtolower($text, 'UTF-8'), self::LATIN_TO_ARABIC)
            : strtr($text, self::arabicToLatin());

        return $swapped !== $text ? $swapped : null;
    }

    /**
     * @return array<string, string>
     */
    private static function arabicToLatin(): array
    {
        static $map = null;

        if ($map === null) {
            $map = array_flip(self::LATIN_TO_ARABIC);
            // حروف المفاتيح مع Shift، والبحث لا يفرّق بين حالتي الحرف اللاتيني.
            $map += ['أ' => 'h', 'إ' => 'y', 'آ' => 'n', 'ـ' => 'j'];
        }

        return $map;
    }
}
