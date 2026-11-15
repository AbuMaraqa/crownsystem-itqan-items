/** One account line of a balance sheet or income statement section (`ReportController::section()`). */
export interface SectionRow {
    account_id: number;
    code: string;
    name: string;
    amount: string;
}

/** A section with its accounts, extra lines (e.g. the unclosed profit) and the service's total. */
export interface ReportSection {
    title: string;
    rows: SectionRow[];
    extra: { label: string; amount: string }[];
    total: string;
}

/** Table classes shared by the report pages, like the journal entry lines table. */
export const tableClasses = {
    table: 'w-full text-sm',
    head: 'bg-gray-50/80 text-[13px] text-gray-500 dark:bg-white/[0.02] dark:text-gray-400',
    th: 'px-4 py-2.5 font-medium',
    row: 'border-b border-line last:border-b-0',
    td: 'px-4 py-2.5',
    foot: 'border-t border-line bg-gray-50/80 font-semibold dark:bg-white/[0.02]',
    number: 'px-4 py-2.5 text-end tabular-nums whitespace-nowrap',
    link: 'text-brand-600 hover:underline dark:text-brand-400',
    empty: 'px-4 py-8 text-center text-muted',
} as const;
