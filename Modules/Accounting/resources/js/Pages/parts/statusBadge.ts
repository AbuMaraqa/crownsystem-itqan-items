import type { Tone } from '@/lib/tones';

/** A status as `Modules\Accounting\Support\StatusBadge::of()` sends it: `<Badge :tone>{{ label }}</Badge>`. */
export interface StatusBadge {
    label: string;
    tone: Tone;
}
