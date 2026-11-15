import type { OrderItemChoice } from '../types';

/** An order item ticked (or not) for an additional cost or a reappraisal, with its note. */
export interface PickedItem {
    consolidated_purchase_order_item_id: number;
    selected: boolean;
    notes: string | null;
}

/**
 * One row per item of the order, ticked when the record already has it. The list is built from the
 * order's items, not from the record, so an item added to the order later shows on the next edit.
 */
export function pickedItems(choices: OrderItemChoice[], chosen: { id: number; notes: string | null }[] = []): PickedItem[] {
    return choices.map((choice) => {
        const saved = chosen.find((item) => item.id === choice.id);

        return { consolidated_purchase_order_item_id: choice.id, selected: !!saved, notes: saved?.notes ?? null };
    });
}
