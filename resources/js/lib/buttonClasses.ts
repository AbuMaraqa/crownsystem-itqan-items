import { cn } from './cn';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'light';
export type ButtonSize = 'sm' | 'md' | 'icon' | 'icon-md';

const base =
    'inline-flex shrink-0 items-center justify-center gap-1.5 rounded-control text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:shadow-focus-ring disabled:pointer-events-none disabled:opacity-50 aria-busy:cursor-wait [&_svg]:shrink-0 [&_svg:not([class*=size-])]:size-4';

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600',
    secondary: 'border border-gray-200 bg-card text-gray-800 shadow-theme-xs hover:bg-gray-100/60 hover:text-gray-950 dark:border-gray-800 dark:text-gray-200 dark:hover:bg-white/5 dark:hover:text-white',
    ghost: 'text-gray-700 hover:bg-gray-100 hover:text-gray-950 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white',
    danger: 'bg-error-600 text-white shadow-theme-xs hover:bg-error-700',
    light: 'bg-brand-50 text-brand-600 hover:bg-brand-100 dark:bg-brand-500/15 dark:text-brand-400 dark:hover:bg-brand-500/25',
};

const sizes: Record<ButtonSize, string> = {
    sm: 'h-control-sm px-2.5 text-xs',
    md: 'h-control px-3',
    icon: 'size-control-sm',
    'icon-md': 'size-control [&_svg:not([class*=size-])]:size-[18px]',
};

/** The classes of a button, for a <button>, a link or a menu trigger alike. */
export function buttonClasses(options: { variant?: ButtonVariant; size?: ButtonSize; class?: string } = {}): string {
    return cn(base, variants[options.variant ?? 'secondary'], sizes[options.size ?? 'md'], options.class);
}
