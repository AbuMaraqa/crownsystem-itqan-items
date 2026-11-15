/** The look of the grid's cells: one place, so every document's grid matches. */
export const cellInput =
    'h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-card px-2.5 text-sm text-gray-900 outline-none transition-[border-color,box-shadow] placeholder:text-gray-400 hover:border-gray-300 focus:border-brand-300 focus:shadow-focus-ring read-only:cursor-default read-only:bg-gray-50 read-only:text-gray-500 read-only:hover:border-gray-200 dark:border-gray-800 dark:text-white dark:placeholder:text-gray-500 dark:read-only:bg-white/[0.03] dark:read-only:text-gray-400';

export const numberInput = `${cellInput} text-end tabular-nums`;

/** A figure the grid computes: shown as a field, never typed. */
export const computedCell =
    'flex h-9 w-full items-center justify-end rounded-lg border border-dashed border-gray-200 bg-gray-50/70 px-2.5 text-sm tabular-nums text-gray-700 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300';

export const pieceInput =
    'h-8 w-full min-w-0 rounded-md border bg-card px-2 text-xs text-gray-900 outline-none transition-[border-color,box-shadow] focus:border-brand-300 focus:shadow-focus-ring read-only:bg-gray-50 read-only:text-gray-500 dark:text-white dark:read-only:bg-white/[0.03]';

export const pieceInputOk = 'border-gray-200 dark:border-gray-800';

export const pieceInputMissing = 'border-error-300 bg-error-50 ring-1 ring-error-300 dark:border-error-500/50 dark:bg-error-500/10';

export const iconButton =
    'inline-flex size-8 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 focus-visible:shadow-focus-ring outline-none dark:hover:bg-white/5 dark:hover:text-gray-200';
