<?php

namespace Modules\Items\Support\Itqan;

/**
 * سطر واحد من ملف ربط تصنيفات إتقان: الى اين تذهب عقدة التصنيف القديمة.
 * action: keep او skip. categoryPath: مسار التصنيف الجديد مفصولاً بـ /.
 * finish: اسم اللون لتصنيفات الالمنيوم، وnull لغيرها. pricingType: weight او simple.
 */
final class CategoryMapEntry
{
    public const KEEP = 'keep';

    public const SKIP = 'skip';

    public function __construct(
        public readonly string $legacyCode,
        public readonly string $legacyName,
        public readonly int $items,
        public readonly string $action,
        public readonly string $categoryPath,
        public readonly ?string $finish,
        public readonly string $pricingType,
        public readonly string $note = '',
    ) {}

    public function kept(): bool
    {
        return $this->action === self::KEEP;
    }
}
