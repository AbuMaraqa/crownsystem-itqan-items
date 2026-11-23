import type { StatusBadge } from '../../parts/statusBadge';

/** One account of the chart, as `Modules\Accounting\Support\AccountTree` sends it. */
export interface AccountNode {
    id: number;
    code: string;
    name: string;
    description: string | null;
    level: number;
    type: StatusBadge | null;
    normal_balance: StatusBadge | null;
    balance: string;
    balance_direction: StatusBadge | null;
    is_postable: boolean;
    is_active: boolean;
    has_children: boolean;
    children: AccountNode[];
}

/** One class of the chart (assets, liabilities...), as `AccountTree::summary()` sends it. */
export interface AccountClass {
    value: number;
    label: string;
    tone: StatusBadge['tone'];
    count: number;
    balance: string;
    balance_direction: StatusBadge | null;
}

export interface AccountSummary {
    classes: AccountClass[];
    /** Debits minus credits of the whole chart; null when they are equal. */
    difference: string | null;
}

/** Text color of a tone; rails and dots take it with `bg-current`. */
export { toneText } from '@/lib/tones';
