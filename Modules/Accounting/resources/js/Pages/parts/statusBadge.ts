/** A status as `Modules\Accounting\Support\StatusBadge::of()` sends it: `<Badge :tone>{{ label }}</Badge>`. */
export interface StatusBadge {
    label: string;
    tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';
}
