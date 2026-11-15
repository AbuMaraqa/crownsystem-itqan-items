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
