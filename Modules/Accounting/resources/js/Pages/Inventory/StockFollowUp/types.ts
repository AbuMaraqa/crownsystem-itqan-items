import type { StatusBadge, UserCellData } from '../parts/types';

/** One order suggestion (StockFollowUpController::suggestion): `order` with a quantity, `none`, `no_data`. */
export interface Suggestion {
    state: string;
    quantity: string | null;
}

/** The item window of the follow-up page (StockFollowUpController::details). */
export interface Details {
    id: number;
    label: string;
    code: string | null;
    main_product: string | null;
    since: string | null;
    quantity: string;
    max_limit: string;
    min_limit_1: string;
    min_limit_2: string;
    limits: { max_limit: number; min_limit_1: number; min_limit_2: number };
    status: StatusBadge;
    from: string;
    to: string;
    day: string | null;
    ledger: {
        opening: string;
        incoming: string;
        outgoing: string;
        closing: string;
        days: { date: string; label: string; opening: string; incoming: string | null; outgoing: string | null; closing: string; movements: number; status: StatusBadge | null }[];
    };
    movements:
        | {
              id: number;
              time: string | null;
              type: StatusBadge | null;
              quantity: string;
              incoming: boolean;
              balance: string;
              user: UserCellData | null;
              reference_no: string | null;
              url: string | null;
              description: string | null;
              warehouse: string | null;
          }[]
        | null;
    suggestions: {
        by_max: Suggestion & { max: string; current: string; value: string };
        by_usage: Suggestion & { has_data: boolean; months: number | null; withdrawn: string; projected: string; current: string; value: string };
    } | null;
    history: { id: number; user: UserCellData | null; at: string | null; old: string; new: string }[];
}
