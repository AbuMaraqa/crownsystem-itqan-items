/**
 * The tones of the panel: `<Badge :tone>` and any coloured figure (a quantity, a rail, a dot).
 * `Modules\Accounting\Support\StatusBadge::tone()` sends these names. `stock-min-1` / `stock-min-2` are
 * the stock limit colors chosen in the general settings (`--color-stock-min-*`, DashboardTheme).
 */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand' | 'stock-min-1' | 'stock-min-2';

/*
 * Any chosen stock color stays readable as text: mixed with black on light cards and with white on dark ones.
 * The mix is in the class (not a :root variable), so an element that sets its own --color-stock-min-* (the
 * settings preview) gets matching text too.
 */
const stockText = {
    'stock-min-1': 'text-[color-mix(in_srgb,var(--color-stock-min-1)_72%,black)] dark:text-[color-mix(in_srgb,var(--color-stock-min-1)_70%,white)]',
    'stock-min-2': 'text-[color-mix(in_srgb,var(--color-stock-min-2)_72%,black)] dark:text-[color-mix(in_srgb,var(--color-stock-min-2)_70%,white)]',
};

/** Badge look of each tone: border, background and text. */
export const toneBadge: Record<Tone, string> = {
    neutral: 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-white/5 dark:text-gray-300',
    success: 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/25 dark:bg-success-500/10 dark:text-success-400',
    warning: 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-500/25 dark:bg-warning-500/10 dark:text-warning-400',
    danger: 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/25 dark:bg-error-500/10 dark:text-error-400',
    info: 'border-blue-light-200 bg-blue-light-50 text-blue-light-700 dark:border-blue-light-500/25 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    brand: 'border-brand-200 bg-brand-50 text-brand-600 dark:border-brand-500/25 dark:bg-brand-500/15 dark:text-brand-400',
    'stock-min-1': `border-stock-min-1/35 bg-stock-min-1/10 dark:border-stock-min-1/30 dark:bg-stock-min-1/15 ${stockText['stock-min-1']}`,
    'stock-min-2': `border-stock-min-2/35 bg-stock-min-2/10 dark:border-stock-min-2/30 dark:bg-stock-min-2/15 ${stockText['stock-min-2']}`,
};

/** Text color of a tone; rails and dots take it with `bg-current`. */
export const toneText: Record<Tone, string> = {
    neutral: 'text-gray-500 dark:text-gray-400',
    success: 'text-success-600 dark:text-success-400',
    warning: 'text-warning-600 dark:text-warning-400',
    danger: 'text-error-600 dark:text-error-400',
    info: 'text-blue-light-600 dark:text-blue-light-400',
    brand: 'text-brand-600 dark:text-brand-400',
    ...stockText,
};

/** A stock quantity at or below a minimum limit, or out of stock: the tones that color the quantity itself. */
export function isLowStockTone(tone: Tone | null | undefined): tone is 'stock-min-1' | 'stock-min-2' | 'danger' {
    return tone === 'stock-min-1' || tone === 'stock-min-2' || tone === 'danger';
}
