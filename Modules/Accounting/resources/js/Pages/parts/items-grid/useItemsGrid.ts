import { computed, nextTick, onBeforeUnmount, reactive, ref, watch, type InjectionKey, type Ref } from 'vue';

import { createGridApi } from './api';
import type {
    AccessoryPayload,
    BarcodeMatch,
    DocumentTotals,
    ExtraLine,
    GridConfig,
    GridContext,
    GridPiece,
    GridRow,
    LineNotice,
    PieceChoice,
    ProductPayload,
    SearchResult,
    StockInfo,
    Suggestion,
} from './types';

/**
 * The state and keyboard logic of the shared items grid. Everything that decides a price, a weight
 * rule or a stock figure comes from the server (api.ts → ItemsGridController); the numbers shown
 * while typing are the same formulas as WeightPieces / DocumentLineTotals, for display only — the
 * saving service computes them again, and Support\ItemsGridTotals answers the notices and totals.
 */

export const gridKey: InjectionKey<ItemsGridState> = Symbol('itemsGrid');

type Column = 'product' | 'quantity' | 'length' | 'actual_weight' | 'unit_price' | 'discount_rate' | 'discount_amount';
type PieceColumn = 'output' | 'source' | 'piece_count' | 'piece_length' | 'weight_per_meter';

export interface Anchor {
    top: number;
    left: number;
    width: number;
}

const uid = (): string => 'row-' + Math.random().toString(36).slice(2, 11);

export const num = (value: unknown): number => {
    const parsed = Number(String(value ?? '').replace(/[\s,]/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
};

export const round4 = (value: number): number => Math.round(value * 10000) / 10000;

export const fixed4 = (value: number): string => round4(value).toFixed(4);

/** 1234.5 → "1,234.5000": totals and line amounts. */
export const money = (value: unknown, decimals = 4): string =>
    num(value).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

/** 52.0000 → "52": quantities and suggested counts. */
export const quantityLabel = (value: unknown): string => num(value).toLocaleString('en-US', { maximumFractionDigits: 4 });

export function normalizePiece(piece: Partial<GridPiece>): GridPiece {
    return {
        ...piece,
        _key: piece._key || uid(),
        product_id: piece.product_id ?? null,
        product_label: piece.product_label ?? '',
        source_product_id: piece.source_product_id ?? null,
        source_product_label: piece.source_product_label ?? '',
        piece_count: piece.piece_count ?? 1,
        piece_length: piece.piece_length ?? '0.0000',
        weight_per_meter: piece.weight_per_meter ?? '0.0000',
        total_weight: piece.total_weight ?? '0.0000',
    };
}

export function normalizeRow(item: Partial<GridRow>): GridRow {
    const categoryId = item.category_id;

    return {
        ...item,
        _key: item._key || uid(),
        row_type: item.row_type === 'category' ? 'category' : 'item',
        // Always a number: a group header and its saved items ("5") must compare equal.
        category_id: categoryId === null || categoryId === undefined || (categoryId as unknown) === '' ? null : Number(categoryId),
        category_label: item.category_label ?? '',
        product_id: item.product_id ? Number(item.product_id) : null,
        product_label: item.product_label ?? '',
        product_search: item.product_search ?? item.product_label ?? '',
        pricing_type: item.pricing_type || 'simple',
        has_sub_products: Boolean(item.has_sub_products),
        quantity: item.quantity ?? 1,
        length: item.length ?? null,
        actual_weight: item.actual_weight ?? null,
        unit_price: item.unit_price ?? '0.0000',
        discount_mode: item.discount_mode === 'amount' ? 'amount' : 'rate',
        discount_rate: item.discount_rate ?? 0,
        discount_amount: item.discount_amount ?? '0.0000',
        line_total: item.line_total ?? '0.0000',
        total_weight: item.total_weight ?? '0.0000',
        weight_factor: item.weight_factor ?? 0,
        // A main item's sub-items are open by default, as in the old system.
        collapsed: Boolean(item.collapsed),
        pieces: (item.pieces ?? []).map((piece) => normalizePiece(piece)),
    };
}

/**
 * A quantity line's amounts (DocumentLineTotals::line): gross, the discount by rate or amount
 * (clamped) and the derived other field. Display only; the saving service computes them again.
 */
export function extraLineAmounts(line: ExtraLine): { gross: number; discount: number; rate: number; total: number } {
    const gross = round4(Math.max(num(line.quantity), 0) * Math.max(num(line.unit_price), 0));
    let discount: number;
    let rate: number;

    if (line.discount_mode === 'amount') {
        discount = round4(Math.min(Math.max(num(line.discount_amount), 0), gross));
        rate = gross > 0 ? round4((discount / gross) * 100) : 0;
    } else {
        rate = Math.min(Math.max(num(line.discount_rate), 0), 100);
        discount = round4(gross * (rate / 100));
    }

    return { gross, discount, rate, total: Math.max(round4(gross - discount), 0) };
}

/**
 * The kg a per-kg service suggests: the customer's
 * materials first (a service on their material), else the weight items of the document (the actual
 * weight, else their pieces).
 */
export function documentWeight(materialsWeight: number, rows: GridRow[]): number {
    if (materialsWeight > 0) return round4(materialsWeight);

    let total = 0;

    for (const row of rows) {
        if (row.row_type === 'category' || row.pricing_type !== 'weight') continue;

        const actual = num(row.actual_weight);
        total += actual > 0 ? actual : row.pieces.reduce((sum, piece) => sum + num(piece.piece_count) * num(piece.piece_length) * num(piece.weight_per_meter), 0);
    }

    return round4(total);
}

export const blankRow = (): GridRow => normalizeRow({ id: null, product_id: null, quantity: 1, unit_price: '0.0000', pieces: [] });

const emptyTotals = (): DocumentTotals => ({ sub_total: 0, items_discount: 0, document_discount: 0, tax_amount: 0, total_amount: 0 });

export function useItemsGrid(rows: Ref<GridRow[]>, configSource: () => GridConfig, context: () => GridContext, root: Ref<HTMLElement | null>) {
    const config = computed(() => {
        const source = configSource();
        const showPrices = source.showPrices ?? true;

        return {
            ...source,
            pricesEditable: source.pricesEditable ?? source.editable,
            actualWeightEditable: source.actualWeightEditable ?? source.editable,
            quantitiesEditable: source.quantitiesEditable ?? source.editable,
            quantityLimitLabel: source.quantityLimitLabel ?? null,
            showPrices,
            showDiscount: showPrices && Boolean(source.showDiscount),
            showLength: Boolean(source.showLength),
            barcode: source.barcode ?? source.editable,
            groups: source.groups ?? true,
        };
    });

    const api = createGridApi(configSource().document, context);

    /* ---------- rows ---------- */

    function seed(): void {
        rows.value.splice(0, rows.value.length, ...rows.value.map((row) => normalizeRow(row)));

        if (config.value.editable && rows.value.length === 0) {
            rows.value.push(blankRow());
        }
    }

    seed();

    // The page replaced the lines (saved again, with their ids): normalize the new ones.
    watch(
        () => rows.value,
        () => {
            seed();
            pieceChoices.value = {};
            suggestions.value = {};
        },
    );

    const isItem = (row: GridRow | undefined): boolean => Boolean(row) && row!.row_type !== 'category';

    /** Items are numbered; group headers are not. */
    function itemNumber(rowIndex: number): number {
        let number = 0;

        for (let index = 0; index <= rowIndex; index++) {
            if (isItem(rows.value[index])) number++;
        }

        return number;
    }

    function groupCount(rowIndex: number): number {
        const group = rows.value[rowIndex];
        let count = 0;

        for (let index = rowIndex + 1; index < rows.value.length; index++) {
            const row = rows.value[index];

            if (row.row_type === 'category' || row.category_id !== group.category_id) break;

            count++;
        }

        return count;
    }

    /** A group has no price: its figures add up its items, the kg price is derived from them. */
    function groupTotals(rowIndex: number): { totalWeight: number; actualWeight: number; lineTotal: number; pricePerKg: number } {
        const totals = { totalWeight: 0, actualWeight: 0, lineTotal: 0, pricePerKg: 0 };
        const count = groupCount(rowIndex);

        for (let index = rowIndex + 1; index <= rowIndex + count; index++) {
            const row = rows.value[index];
            totals.totalWeight += num(row.total_weight);
            totals.actualWeight += num(row.actual_weight);
            totals.lineTotal += num(row.line_total);
        }

        const billable = totals.actualWeight > 0 ? totals.actualWeight : totals.totalWeight;
        totals.pricePerKg = billable > 0 ? totals.lineTotal / billable : 0;

        return totals;
    }

    function addRow(focus = false): void {
        if (!config.value.editable) return;

        rows.value.push(blankRow());

        if (focus) focusCell(rows.value.length - 1, 'product');
    }

    function addItemToGroup(rowIndex: number, focus = false): void {
        const group = rows.value[rowIndex];

        if (!config.value.editable || !group || group.row_type !== 'category') return;

        const insertAt = rowIndex + 1 + groupCount(rowIndex);
        const row = blankRow();
        row.category_id = group.category_id;
        row.category_label = group.category_label;
        rows.value.splice(insertAt, 0, row);

        if (focus) focusCell(insertAt, 'product');
    }

    /** Removing a group header removes its items with it. */
    function removeRow(rowIndex: number): void {
        if (!config.value.editable) return;

        const span = rows.value[rowIndex]?.row_type === 'category' ? 1 + groupCount(rowIndex) : 1;
        rows.value.splice(rowIndex, span);

        if (rows.value.length === 0) rows.value.push(blankRow());
    }

    function addPiece(rowIndex: number): void {
        if (!config.value.editable) return;

        rows.value[rowIndex].pieces.push(normalizePiece({}));
        focusPieceCell(rowIndex, rows.value[rowIndex].pieces.length - 1, 'output');
    }

    /** Opens a main item's sub-items and focuses an empty one (or a new one) to pick from its tree. */
    function openSubItems(rowIndex: number, addNew = false): void {
        const row = rows.value[rowIndex];

        if (!config.value.editable || !row) return;

        row.collapsed = false;
        const last = row.pieces.length - 1;
        let pieceIndex = addNew ? (last >= 0 && !row.pieces[last].product_id ? last : -1) : row.pieces.findIndex((piece) => !piece.product_id);

        if (pieceIndex === -1) {
            row.pieces.push(normalizePiece({}));
            pieceIndex = row.pieces.length - 1;
        }

        focusPieceCell(rowIndex, pieceIndex, 'output');
    }

    function removePiece(rowIndex: number, pieceIndex: number): void {
        if (!config.value.editable) return;

        rows.value[rowIndex].pieces.splice(pieceIndex, 1);
    }

    /* ---------- display amounts (same formulas as the server; display only) ---------- */

    const localTotals = ref<DocumentTotals>(emptyTotals());

    function recalculate(): void {
        let subTotal = 0;
        let itemsDiscount = 0;

        for (const row of rows.value) {
            if (row.row_type === 'category') continue;

            const isWeight = row.pricing_type === 'weight';
            let totalWeight = 0;

            for (const piece of row.pieces) {
                const weight = round4(Math.max(num(piece.piece_count), 0) * Math.max(num(piece.piece_length), 0) * Math.max(num(piece.weight_per_meter), 0));
                totalWeight += piece.product_id ? weight : 0;
                setIfChanged(piece, 'total_weight', fixed4(weight));
            }

            if (isWeight) setIfChanged(row, 'total_weight', fixed4(totalWeight));

            // WeightPieces::line: the actual weight, zero without it when there are pieces; an old
            // weight line without pieces keeps its quantity. Returns price the invoiced kg factor.
            const actual = num(row.actual_weight);
            const hasPieces = row.pieces.some((piece) => piece.product_id);
            let quantity = row.quantity === null || row.quantity === '' ? 1 : num(row.quantity);

            if (isWeight) {
                quantity = actual > 0 ? actual : hasPieces ? 0 : quantity;

                if (!(actual > 0) && num(row.weight_factor) > 0) quantity = round4(totalWeight * num(row.weight_factor));
            }

            const gross = round4(Math.max(quantity, 0) * Math.max(num(row.unit_price), 0));
            let discountAmount: number;

            if (row.discount_mode === 'amount') {
                discountAmount = round4(Math.min(Math.max(num(row.discount_amount), 0), gross));
                setIfChanged(row, 'discount_rate', fixed4(gross > 0 ? (discountAmount / gross) * 100 : 0));
            } else {
                discountAmount = round4(gross * (Math.min(Math.max(num(row.discount_rate), 0), 100) / 100));
                setIfChanged(row, 'discount_amount', fixed4(discountAmount));
            }

            setIfChanged(row, 'line_total', fixed4(Math.max(gross - discountAmount, 0)));

            if (row.product_id) {
                subTotal += gross;
                itemsDiscount += discountAmount;
            }
        }

        // Lines outside the grid (service lines): quantity × price less their discount.
        for (const line of context().extraLines ?? []) {
            if (!line.product_id) continue;

            const amounts = extraLineAmounts(line);
            subTotal += amounts.gross;
            itemsDiscount += amounts.discount;
        }

        const { documentDiscount, taxRate } = context();
        const net = Math.max(round4(subTotal - itemsDiscount), 0);
        const discount = Math.min(Math.max(num(documentDiscount), 0), net);
        const taxable = Math.max(round4(net - discount), 0);
        const tax = round4(taxable * (num(taxRate) / 100));

        localTotals.value = {
            sub_total: round4(subTotal),
            items_discount: round4(itemsDiscount),
            document_discount: round4(discount),
            tax_amount: tax,
            total_amount: round4(taxable + tax),
        };
    }

    function setIfChanged<T extends object>(target: T, key: keyof T & string, value: string): void {
        if (String((target as Record<string, unknown>)[key] ?? '') !== value) {
            (target as Record<string, unknown>)[key] = value;
        }
    }

    /** Discount typed in one field: its mode follows that field and the other is derived. */
    function setDiscountMode(row: GridRow, mode: 'rate' | 'amount'): void {
        row.discount_mode = mode;
    }

    /** On leaving a discount field its own value is clamped (0–100%, or up to the line amount). */
    function normalizeDiscount(row: GridRow): void {
        if (row.discount_mode === 'rate') {
            row.discount_rate = fixed4(Math.min(Math.max(num(row.discount_rate), 0), 100));
        } else {
            row.discount_amount = fixed4(Math.max(num(row.discount_amount), 0));
        }
    }

    /* ---------- server totals and notices ---------- */

    const notices = ref<Record<string, LineNotice[]>>({});
    const serverTotals = ref<DocumentTotals | null>(null);
    let version = 0;
    let totalsTimer: ReturnType<typeof setTimeout> | undefined;
    let totalsRequest: AbortController | undefined;

    function payloadRows(): Record<string, unknown>[] {
        return rows.value
            .filter((row) => row.row_type !== 'category' && row.product_id)
            .map((row) => ({
                key: row._key,
                id: row.id ?? null,
                product_id: row.product_id,
                quantity: row.quantity,
                actual_weight: row.actual_weight,
                unit_price: row.unit_price,
                discount_mode: row.discount_mode,
                discount_rate: row.discount_rate,
                discount_amount: row.discount_amount,
                weight_factor: row.weight_factor ?? 0,
                pieces: row.pieces
                    .filter((piece) => piece.product_id)
                    .map((piece) => ({ key: piece._key, product_id: piece.product_id, piece_count: piece.piece_count, piece_length: piece.piece_length, weight_per_meter: piece.weight_per_meter })),
            }));
    }

    let lastBody = '';

    /** Only when an amount changed: typing an item's name does not ask the server again. */
    function scheduleServerTotals(): void {
        const { documentDiscount, taxRate, extraLines } = context();
        const extra = (extraLines ?? [])
            .filter((line) => line.product_id)
            .map((line) => ({
                key: line._key ?? '',
                product_id: line.product_id,
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_mode: line.discount_mode ?? 'rate',
                discount_rate: line.discount_rate ?? 0,
                discount_amount: line.discount_amount ?? 0,
            }));
        // Pages without extra lines send the same body as before.
        const body = { items: payloadRows(), ...(extra.length ? { extra_lines: extra } : {}), document_discount: num(documentDiscount), tax_rate: num(taxRate) };
        const signature = JSON.stringify(body);

        if (signature === lastBody) return;

        lastBody = signature;
        const current = ++version;
        serverTotals.value = null;
        clearTimeout(totalsTimer);

        totalsTimer = setTimeout(async () => {
            totalsRequest?.abort();
            totalsRequest = new AbortController();

            try {
                const result = await api.totals(body, totalsRequest.signal);

                if (current !== version) return;

                notices.value = Object.fromEntries(result.lines.map((line) => [line.key, line.notices]));
                serverTotals.value = result.totals;
            } catch {
                // A newer change replaced this request, or the network failed: the local figures stay.
            }
        }, 350);
    }

    const totals = computed<DocumentTotals>(() => serverTotals.value ?? localTotals.value);

    watch(
        () => [rows.value, context().documentDiscount, context().taxRate, context().extraLines],
        () => {
            recalculate();
            scheduleServerTotals();
        },
        { deep: true, immediate: true },
    );

    function rowNotices(row: GridRow): LineNotice[] {
        return (notices.value[row._key] ?? []).filter((notice) => notice.type !== 'actual_weight_empty' || config.value.showPrices);
    }

    /** A zero count, length or weight per meter makes the sub-item weightless (returns: zero means "not returned"). */
    function missingMeasure(piece: GridPiece, field: 'piece_count' | 'piece_length' | 'weight_per_meter'): boolean {
        if (field === 'piece_count' && config.value.quantityLimitLabel) return false;

        return Boolean(piece.product_id) && !(num(piece[field]) > 0);
    }

    function clampQuantity(row: GridRow): void {
        if (!config.value.quantityLimitLabel || row.max_quantity === undefined || row.max_quantity === null) return;

        if (num(row.quantity) > num(row.max_quantity)) row.quantity = String(num(row.max_quantity));
    }

    function clampPieceCount(piece: GridPiece): void {
        if (!config.value.quantityLimitLabel || piece.max_piece_count === undefined || piece.max_piece_count === null) return;

        if (num(piece.piece_count) > num(piece.max_piece_count)) piece.piece_count = String(num(piece.max_piece_count));
    }

    /* ---------- keyboard navigation ---------- */

    function priceColumns(): Column[] {
        return config.value.showDiscount ? ['unit_price', 'discount_rate', 'discount_amount'] : ['unit_price'];
    }

    function itemColumns(row: GridRow): Column[] {
        const columns: Column[] = ['product'];

        if (row.pricing_type === 'weight') {
            if (config.value.showLength) columns.push('length');
            columns.push('actual_weight');
        } else {
            columns.push('quantity');
            if (config.value.showLength) columns.push('length');
        }

        return columns;
    }

    /** Locked items: only what can still be typed (scale weight, quantities, prices). */
    function navColumns(row: GridRow): Column[] {
        const c = config.value;

        if (!c.editable) {
            const columns: Column[] = row.pricing_type === 'weight' ? (c.actualWeightEditable ? ['actual_weight'] : []) : c.quantitiesEditable ? ['quantity'] : [];
            const open = columns.concat(c.showPrices && c.pricesEditable ? priceColumns() : []);

            return open.length ? open : ['product'];
        }

        return itemColumns(row).concat(c.showPrices ? priceColumns() : []);
    }

    function field(selector: string): HTMLInputElement | null {
        return root.value?.querySelector<HTMLInputElement>(selector) ?? null;
    }

    function focusCell(rowIndex: number, column: string): void {
        nextTick(() => {
            const input = field(`[data-cell="${rowIndex}:${column}"]`);
            input?.focus();
            input?.select?.();
        });
    }

    function focusPieceCell(rowIndex: number, pieceIndex: number, column: PieceColumn): void {
        nextTick(() => {
            const input = field(`[data-pcell="${rowIndex}:${pieceIndex}:${column}"]`);
            input?.focus();
            input?.select?.();
        });
    }

    function moveNext(rowIndex: number, column: Column): void {
        const row = rows.value[rowIndex];

        if (!row) return;

        const columns = navColumns(row);
        const position = columns.indexOf(column);

        if (position > -1 && position < columns.length - 1) {
            focusCell(rowIndex, columns[position + 1]);

            return;
        }

        if (rowIndex >= rows.value.length - 1) {
            if (!config.value.editable) return;

            // Enter on the last row adds a row, inside the same group.
            const next = blankRow();
            next.category_id = row.category_id ?? null;
            next.category_label = row.category_label ?? '';
            rows.value.push(next);
            focusCell(rowIndex + 1, 'product');

            return;
        }

        focusCell(rowIndex + 1, navColumns(rows.value[rowIndex + 1])[0]);
    }

    function moveRow(rowIndex: number, column: Column, offset: number): void {
        const target = rowIndex + offset;

        if (target < 0 || target >= rows.value.length) return;

        const columns = navColumns(rows.value[target]);
        focusCell(target, columns.includes(column) ? column : columns[0]);
    }

    function onCellKey(event: KeyboardEvent, rowIndex: number, column: Column): void {
        if (event.key === 'Enter') {
            event.preventDefault();
            moveNext(rowIndex, column);
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveRow(rowIndex, column, 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveRow(rowIndex, column, -1);
        } else if (event.key === 'Delete' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            removeRow(rowIndex);
        }
    }

    /** Enter order inside a sub-item. The source item (cutting recipe) is optional, outside it. */
    function pieceColumns(): PieceColumn[] {
        if (!config.value.editable) return config.value.quantitiesEditable ? ['piece_count'] : [];

        return ['output', 'piece_count', 'piece_length', 'weight_per_meter'];
    }

    function movePieceNext(rowIndex: number, pieceIndex: number, column: PieceColumn): void {
        const columns = pieceColumns();
        const position = columns.indexOf(column);

        if (column === 'source') {
            focusPieceCell(rowIndex, pieceIndex, 'output');

            return;
        }

        if (position > -1 && position < columns.length - 1) {
            focusPieceCell(rowIndex, pieceIndex, columns[position + 1]);

            return;
        }

        if (pieceIndex >= rows.value[rowIndex].pieces.length - 1) {
            if (config.value.editable) addPiece(rowIndex);

            return;
        }

        focusPieceCell(rowIndex, pieceIndex + 1, columns[0]);
    }

    function onPieceCellKey(event: KeyboardEvent, rowIndex: number, pieceIndex: number, column: PieceColumn): void {
        if (event.key === 'Enter') {
            event.preventDefault();
            movePieceNext(rowIndex, pieceIndex, column);

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const target = pieceIndex + (event.key === 'ArrowDown' ? 1 : -1);

            if (target < 0 || target >= rows.value[rowIndex].pieces.length) return;

            event.preventDefault();
            focusPieceCell(rowIndex, target, column);

            return;
        }

        if (event.key === 'Delete' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            removePiece(rowIndex, pieceIndex);
        }
    }

    /* ---------- dropdown position (teleported, fixed to the screen) ---------- */

    const productCell = (rowIndex: number | null): HTMLInputElement | null => (rowIndex === null ? null : field(`[data-cell="${rowIndex}:product"]`));
    const pieceCell = (rowIndex: number | null, pieceIndex: number | null, which: 'output' | 'source' | null): HTMLInputElement | null =>
        rowIndex === null || pieceIndex === null || which === null ? null : field(`[data-pcell="${rowIndex}:${pieceIndex}:${which}"]`);

    function anchorOf(input: HTMLElement | null, minWidth: number): Anchor | null {
        if (!input) return null;

        const rect = input.getBoundingClientRect();

        if (rect.width === 0 && rect.height === 0) return null;

        const width = Math.max(rect.width, minWidth);
        const rtl = getComputedStyle(input).direction === 'rtl';
        const left = rtl ? rect.right - width : rect.left;

        return { top: rect.bottom + 4, left: Math.max(8, Math.min(left, window.innerWidth - width - 8)), width };
    }

    /* ---------- item search ---------- */

    const search = reactive({
        rowIndex: null as number | null,
        results: [] as SearchResult[],
        highlight: 0,
        open: false,
        loading: false,
        mode: 'item' as 'item' | 'category',
        anchor: null as Anchor | null,
    });

    const pieceSearch = reactive({
        rowIndex: null as number | null,
        pieceIndex: null as number | null,
        field: null as 'output' | 'source' | null,
        results: [] as SearchResult[],
        highlight: 0,
        open: false,
        loading: false,
        anchor: null as Anchor | null,
    });

    let searchToken = 0;
    let pieceSearchToken = 0;
    let searchRequest: AbortController | undefined;
    let pieceRequest: AbortController | undefined;
    let tracking = false;

    /** The open list follows its field while it is focused and visible; anything else closes it. */
    function trackDropdowns(): void {
        if (tracking) return;

        tracking = true;

        const step = (): void => {
            if (!search.open && !pieceSearch.open) {
                tracking = false;

                return;
            }

            if (search.open) {
                const input = productCell(search.rowIndex);
                const anchor = input && document.activeElement === input ? anchorOf(input, 288) : null;

                if (anchor) search.anchor = anchor;
                else closeSearch();
            }

            if (pieceSearch.open) {
                const input = pieceCell(pieceSearch.rowIndex, pieceSearch.pieceIndex, pieceSearch.field);
                const anchor = input && document.activeElement === input ? anchorOf(input, 256) : null;

                if (anchor) pieceSearch.anchor = anchor;
                else closePieceSearch();
            }

            requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    }

    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    function queueSearch(rowIndex: number, term: string): void {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => searchProducts(rowIndex, term), 220);
    }

    async function searchProducts(rowIndex: number, term: string): Promise<void> {
        const token = ++searchToken;
        const row = rows.value[rowIndex];
        const mode = search.mode;
        const needle = (term ?? '').trim();
        const input = productCell(rowIndex);

        search.rowIndex = rowIndex;

        if (!row) return;

        if (mode === 'item') row.product_id = null;

        // In group mode the categories show even without text, to browse them.
        if (document.activeElement !== input || (needle === '' && mode === 'item')) {
            search.results = [];
            search.open = false;

            return;
        }

        search.anchor = anchorOf(input, 288);
        search.open = true;
        search.loading = true;
        trackDropdowns();

        searchRequest?.abort();
        searchRequest = new AbortController();

        try {
            const results =
                mode === 'category'
                    ? await api.searchCategories(needle, searchRequest.signal)
                    : // Inside a group the search is limited to that group's items.
                      await api.searchProducts(needle, row.category_id ?? null, searchRequest.signal);

            if (token !== searchToken || search.rowIndex !== rowIndex) return;

            search.results = results ?? [];
            search.highlight = 0;
        } catch {
            // Replaced by a newer search.
        } finally {
            if (token === searchToken) search.loading = false;
        }
    }

    function setSearchMode(rowIndex: number, mode: 'item' | 'category'): void {
        search.mode = mode;
        search.results = [];
        search.highlight = 0;

        const input = productCell(rowIndex);
        input?.focus();
        searchProducts(rowIndex, input?.value ?? '');
    }

    function closeSearch(): void {
        searchToken++;
        searchRequest?.abort();
        search.mode = 'item';
        search.open = false;
        search.loading = false;
        search.results = [];
        search.highlight = 0;
    }

    function onProductFocus(rowIndex: number): void {
        search.rowIndex = rowIndex;
    }

    function onProductBlur(rowIndex: number): void {
        setTimeout(() => {
            const row = rows.value[rowIndex];

            if (row && row.row_type !== 'category') row.product_label = row.product_id ? row.product_search : '';

            closeSearch();
        }, 150);
    }

    function onProductKey(event: KeyboardEvent, rowIndex: number): void {
        const open = search.open && search.rowIndex === rowIndex && search.results.length > 0;

        if (event.key === 'ArrowDown' && open) {
            event.preventDefault();
            search.highlight = Math.min(search.highlight + 1, search.results.length - 1);
        } else if (event.key === 'ArrowUp' && open) {
            event.preventDefault();
            search.highlight = Math.max(search.highlight - 1, 0);
        } else if (event.key === 'Escape') {
            closeSearch();
        } else if (event.key === 'Enter' && open) {
            event.preventDefault();
            chooseResult(rowIndex, search.results[search.highlight]);
        } else {
            onCellKey(event, rowIndex, 'product');
        }
    }

    function chooseResult(rowIndex: number, option: SearchResult | undefined): void {
        if (!option) return;

        if (search.mode === 'category') chooseCategory(rowIndex, option);
        else void chooseProduct(rowIndex, option);
    }

    /** The row becomes a group header, and an item row opens under it. */
    function chooseCategory(rowIndex: number, option: SearchResult): void {
        const row = rows.value[rowIndex];

        Object.assign(row, {
            row_type: 'category',
            category_id: option.id,
            category_label: option.label,
            product_id: null,
            product_label: option.label,
            product_search: option.label,
            pricing_type: 'simple',
            quantity: null,
            unit_price: '0.0000',
            line_total: '0.0000',
            pieces: [],
        });

        closeSearch();
        nextTick(() => addItemToGroup(rowIndex, true));
    }

    async function chooseProduct(rowIndex: number, option: SearchResult): Promise<void> {
        const row = rows.value[rowIndex];
        const previousPieces = row.product_id && Number(row.product_id) !== Number(option.id) ? row.pieces.filter((piece) => piece.product_id) : [];

        // The accessories of the item this row had no longer belong to it.
        delete suggestions.value[row._key];

        // The name shows at once; the details follow from the server.
        row.product_id = option.id;
        row.product_label = option.label;
        row.product_search = option.label;

        closeSearch();
        focusCell(rowIndex, navColumns(row)[1] ?? 'product');

        let product: ProductPayload | null = null;

        try {
            product = await api.item(option.id);
        } catch {
            return;
        }

        if (!product || rows.value[rowIndex]?.product_id !== option.id) return;

        // A sub-item is never a line of its own: it goes inside its main item's line.
        if (product.main_product) {
            const target = placeSubItem(rowIndex, product);
            setSuggestions(rows.value[target], product.accessories);

            return;
        }

        Object.assign(row, {
            has_sub_products: Boolean(product.has_sub_products),
            barcode: product.barcode ?? null,
            description: product.description ?? null,
            unit_price: product.unit_price ?? '0.0000',
            pricing_type: product.pricing_type || 'simple',
            length: product.length ?? row.length,
            quantity: row.quantity || 1,
            discount_mode: 'rate',
            discount_rate: 0,
            discount_amount: '0.0000',
        });

        offerPieces(row, previousPieces);
        setSuggestions(row, product.accessories);

        await nextTick();

        // A weight item is a main item: its sub-items are picked straight away.
        if (row.pricing_type === 'weight') {
            openSubItems(rowIndex);

            return;
        }

        const active = document.activeElement as HTMLElement | null;

        if (!active || !root.value?.contains(active) || active.offsetParent === null) {
            focusCell(rowIndex, navColumns(row)[1] ?? 'product');
        }
    }

    /**
     * A sub-item goes into its main item's line in the same group, or the current row becomes that
     * main item's line (with its kg price). Then the sub-items open on its piece count.
     */
    function placeSubItem(rowIndex: number, product: ProductPayload, pieceCount: number | string = 1): number {
        const row = rows.value[rowIndex];
        const main = product.main_product!;
        const sameGroup = (other: GridRow): boolean => (other.category_id ?? null) === (row.category_id ?? null);

        let target = rows.value.findIndex((other, index) => index !== rowIndex && isItem(other) && Number(other.product_id) === Number(main.id) && sameGroup(other));

        if (target === -1) {
            Object.assign(row, {
                product_id: main.id,
                product_label: main.label,
                product_search: main.label,
                barcode: main.barcode ?? null,
                description: main.description ?? null,
                unit_price: main.unit_price ?? '0.0000',
                pricing_type: main.pricing_type || 'weight',
                has_sub_products: true,
                quantity: 1,
                actual_weight: null,
                discount_mode: 'rate',
                discount_rate: 0,
                discount_amount: '0.0000',
                pieces: [],
            });
            target = rowIndex;
        } else {
            // The row the search was typed in is no longer needed.
            rows.value.splice(rowIndex, 1);

            if (target > rowIndex) target--;
            if (rows.value.length === 0) rows.value.push(blankRow());
        }

        const mainRow = rows.value[target];
        const piece = normalizePiece({
            product_id: product.id,
            product_label: product.label,
            barcode: product.barcode ?? null,
            description: product.description ?? null,
            piece_count: pieceCount,
            piece_length: product.length ?? '0.0000',
            weight_per_meter: product.weight_per_meter ?? '0.0000',
            image_url: product.image ?? null,
        });

        // An empty sub-item row waiting for a choice is filled instead of adding one.
        let pieceIndex = mainRow.pieces.findIndex((other) => !other.product_id);

        if (pieceIndex === -1) {
            mainRow.pieces.push(piece);
            pieceIndex = mainRow.pieces.length - 1;
        } else {
            mainRow.pieces.splice(pieceIndex, 1, piece);
        }

        mainRow.collapsed = false;
        focusPieceCell(target, pieceIndex, 'piece_count');

        return target;
    }

    /* ---------- pieces of a line whose main item changed ---------- */

    const pieceChoices = ref<Record<string, PieceChoice>>({});

    /** Kept by default (as in Itqan); the user may match them by name under the new main item or clear them. */
    function offerPieces(row: GridRow, previousPieces: GridPiece[]): void {
        if (row.pricing_type !== 'weight' || previousPieces.length === 0) return;

        row.pieces = previousPieces;
        pieceChoices.value[row._key] = { pieces: previousPieces, unmatched: [], decided: false };
    }

    function clearPieces(rowIndex: number): void {
        const row = rows.value[rowIndex];

        row.pieces = [];
        delete pieceChoices.value[row._key];
    }

    async function matchPieces(rowIndex: number): Promise<void> {
        const row = rows.value[rowIndex];
        const choice = pieceChoices.value[row._key];

        if (!choice || !row.product_id) return;

        const result = await api.matchPieces(row.product_id, choice.pieces.map((piece) => Number(piece.product_id)));

        row.pieces = result.matched.map((match) => {
            const source = choice.pieces.find((piece) => Number(piece.product_id) === Number(match.source_id));

            return normalizePiece({
                product_id: match.product.id,
                product_label: match.product.label,
                barcode: match.product.barcode ?? null,
                description: match.product.description ?? null,
                piece_count: source?.piece_count ?? 1,
                piece_length: source?.piece_length ?? match.product.length ?? '0.0000',
                weight_per_meter: match.product.weight_per_meter ?? '0.0000',
            });
        });

        // What found no match is listed, never dropped silently.
        pieceChoices.value[row._key] = { pieces: [], unmatched: result.unmatched, decided: true };
    }

    function dismissPieceChoice(rowIndex: number): void {
        delete pieceChoices.value[rows.value[rowIndex]._key];
    }

    /* ---------- sub-item search ---------- */

    let pieceTimer: ReturnType<typeof setTimeout> | undefined;

    function queuePieceSearch(rowIndex: number, pieceIndex: number, which: 'output' | 'source', term: string): void {
        clearTimeout(pieceTimer);
        pieceTimer = setTimeout(() => searchPieceProducts(rowIndex, pieceIndex, which, term), 220);
    }

    function onPieceFocus(rowIndex: number, pieceIndex: number, which: 'output' | 'source'): void {
        Object.assign(pieceSearch, { rowIndex, pieceIndex, field: which });
        const row = rows.value[rowIndex];
        const piece = row?.pieces[pieceIndex];

        // Inside a main item its sub-items show on focus, to browse them.
        if (which === 'output' && config.value.editable && row?.has_sub_products && piece && !piece.product_id) {
            void searchPieceProducts(rowIndex, pieceIndex, which, piece.product_label);
        }
    }

    async function searchPieceProducts(rowIndex: number, pieceIndex: number, which: 'output' | 'source', term: string): Promise<void> {
        const token = ++pieceSearchToken;
        Object.assign(pieceSearch, { rowIndex, pieceIndex, field: which });

        const needle = (term ?? '').trim();
        const input = pieceCell(rowIndex, pieceIndex, which);
        const row = rows.value[rowIndex];
        const piece = row?.pieces[pieceIndex];
        const browsesSubItems = which === 'output' && row?.has_sub_products;

        if (!row || !piece || (needle === '' && !browsesSubItems) || document.activeElement !== input) {
            pieceSearch.results = [];
            pieceSearch.open = false;

            return;
        }

        pieceSearch.anchor = anchorOf(input, 256);
        pieceSearch.open = true;
        pieceSearch.loading = true;
        trackDropdowns();

        pieceRequest?.abort();
        pieceRequest = new AbortController();

        try {
            const results =
                which === 'output'
                    ? await api.searchPieceOutputs(needle, piece.source_product_id, row.product_id, pieceRequest.signal)
                    : await api.searchProducts(needle, null, pieceRequest.signal);

            if (token !== pieceSearchToken || pieceSearch.rowIndex !== rowIndex || pieceSearch.pieceIndex !== pieceIndex) return;

            pieceSearch.results = results ?? [];
            pieceSearch.highlight = 0;
        } catch {
            // Replaced by a newer search.
        } finally {
            if (token === pieceSearchToken) pieceSearch.loading = false;
        }
    }

    function closePieceSearch(): void {
        pieceSearchToken++;
        pieceRequest?.abort();
        pieceSearch.open = false;
        pieceSearch.loading = false;
        pieceSearch.results = [];
    }

    function onPieceBlur(): void {
        setTimeout(closePieceSearch, 150);
    }

    function pieceSearchOpenFor(rowIndex: number, pieceIndex: number, which: 'output' | 'source'): boolean {
        return pieceSearch.open && pieceSearch.rowIndex === rowIndex && pieceSearch.pieceIndex === pieceIndex && pieceSearch.field === which;
    }

    function onPieceProductKey(event: KeyboardEvent, rowIndex: number, pieceIndex: number, which: 'output' | 'source'): void {
        const open = pieceSearchOpenFor(rowIndex, pieceIndex, which) && pieceSearch.results.length > 0;

        if (event.key === 'ArrowDown' && open) {
            event.preventDefault();
            pieceSearch.highlight = Math.min(pieceSearch.highlight + 1, pieceSearch.results.length - 1);
        } else if (event.key === 'ArrowUp' && open) {
            event.preventDefault();
            pieceSearch.highlight = Math.max(pieceSearch.highlight - 1, 0);
        } else if (event.key === 'Escape') {
            closePieceSearch();
        } else if (event.key === 'Enter') {
            event.preventDefault();

            if (open) void choosePieceProduct(rowIndex, pieceIndex, which, pieceSearch.results[pieceSearch.highlight]);
            else movePieceNext(rowIndex, pieceIndex, which);
        }
    }

    async function choosePieceProduct(rowIndex: number, pieceIndex: number, which: 'output' | 'source', option: SearchResult | undefined): Promise<void> {
        if (!option) return;

        const piece = rows.value[rowIndex].pieces[pieceIndex];
        closePieceSearch();

        if (which === 'source') {
            Object.assign(piece, { source_product_id: option.id, source_product_label: option.label, product_id: null, product_label: '' });
            movePieceNext(rowIndex, pieceIndex, which);

            return;
        }

        piece.product_id = option.id;
        piece.product_label = option.label;
        movePieceNext(rowIndex, pieceIndex, which);

        let product: ProductPayload | null = null;

        try {
            product = await api.item(option.id, piece.source_product_id);
        } catch {
            return;
        }

        if (!product || piece.product_id !== option.id) return;

        Object.assign(piece, {
            barcode: product.barcode ?? null,
            description: product.description ?? null,
            weight_per_meter: product.weight_per_meter ?? piece.weight_per_meter,
            piece_length: product.length ?? piece.piece_length,
            image_url: product.image ?? null,
        });

        if (product.piece_count) piece.piece_count = product.piece_count;
    }

    /* ---------- barcode entry ---------- */

    const scan = reactive({ code: '', matches: [] as BarcodeMatch[], message: null as string | null, addUrl: null as string | null, busy: false });

    async function scanBarcode(notFound: string): Promise<void> {
        const code = scan.code.trim();

        if (code === '' || scan.busy) return;

        scan.busy = true;

        try {
            const result = await api.barcode(code);
            scan.message = null;
            scan.addUrl = null;
            scan.matches = [];

            if (result.matches.length === 1) {
                scan.code = '';
                await addScanned(result.matches[0]);
            } else if (result.matches.length > 1) {
                // Barcodes repeat across colours by design: the user picks one.
                scan.matches = result.matches;
            } else {
                scan.message = notFound;
                scan.addUrl = result.add_url;
            }
        } finally {
            scan.busy = false;
        }
    }

    async function pickScanned(match: BarcodeMatch): Promise<void> {
        scan.matches = [];
        scan.code = '';
        await addScanned(match);
    }

    function emptyRowIndex(): number {
        const index = rows.value.findIndex((other) => isItem(other) && !other.product_id && !other.category_id);

        if (index !== -1) return index;

        rows.value.push(blankRow());

        return rows.value.length - 1;
    }

    /** A new line, or more of a line already there by the barcode's pack quantity. A piece goes under its main item. */
    async function addScanned(match: BarcodeMatch): Promise<void> {
        const product = await api.item(match.id);

        if (!product) return;

        const pack = num(match.pack_quantity) || 1;
        const lineOf = (id: number): number => rows.value.findIndex((other) => isItem(other) && Number(other.product_id) === Number(id));

        if (product.main_product) {
            const mainIndex = lineOf(product.main_product.id);
            const existing = mainIndex === -1 ? null : rows.value[mainIndex].pieces.find((piece) => Number(piece.product_id) === Number(product.id));

            if (existing) existing.piece_count = num(existing.piece_count) + pack;
            else placeSubItem(emptyRowIndex(), product, pack);

            return;
        }

        const lineIndex = lineOf(product.id);

        if (lineIndex !== -1) {
            const line = rows.value[lineIndex];

            if (line.pricing_type !== 'weight') line.quantity = num(line.quantity) + pack;

            return;
        }

        const index = emptyRowIndex();
        Object.assign(rows.value[index], {
            product_id: product.id,
            product_label: product.label,
            product_search: product.label,
            has_sub_products: Boolean(product.has_sub_products),
            barcode: product.barcode ?? null,
            description: product.description ?? null,
            unit_price: product.unit_price ?? '0.0000',
            pricing_type: product.pricing_type || 'simple',
            length: product.length ?? null,
            quantity: pack,
        });

        await nextTick();

        if (rows.value[index].pricing_type === 'weight') openSubItems(index);
    }

    /* ---------- suggested accessories ---------- */

    // Kept outside the rows on purpose, so they are never sent with the lines.
    const suggestions = ref<Record<string, Suggestion[]>>({});
    const accessoryOf: Record<string, string> = {};

    function pendingSuggestions(row: GridRow): Suggestion[] {
        if (!row?.product_id) return [];

        return (suggestions.value[row._key] ?? []).filter((suggestion) => !suggestion.added);
    }

    /** A sub-item's accessories join its main line's suggestions instead of replacing them. */
    function setSuggestions(row: GridRow | undefined, accessories: AccessoryPayload[] | undefined): void {
        if (!config.value.editable || !row || !accessories?.length) return;

        const list = suggestions.value[row._key] ?? [];

        for (const accessory of accessories) {
            if (!list.some((suggestion) => Number(suggestion.product.id) === Number(accessory.product.id))) {
                list.push({ product: accessory.product, quantity: accessory.quantity, added: false });
            }
        }

        suggestions.value[row._key] = list;
    }

    /** Suggested quantity per unit × the line's quantity. A weight item has no quantity: taken as is. */
    function accessoryQuantity(row: GridRow, suggestion: Suggestion): number {
        const perUnit = num(suggestion.quantity) || 1;
        const factor = row.pricing_type === 'weight' ? 1 : num(row.quantity) || 1;

        return round4(perUnit * factor);
    }

    function insertAccessory(rowIndex: number, suggestion: Suggestion): void {
        const row = rows.value[rowIndex];
        const product = suggestion.product;
        const quantity = accessoryQuantity(row, suggestion);
        const sameGroup = (other: GridRow): boolean => (other.category_id ?? null) === (row.category_id ?? null);

        suggestion.added = true;

        // After the item's row and the accessories already added under it.
        let insertAt = rowIndex + 1;
        while (insertAt < rows.value.length && accessoryOf[rows.value[insertAt]._key] === row._key) insertAt++;

        const blank = blankRow();
        blank.category_id = row.category_id ?? null;
        blank.category_label = row.category_label ?? '';

        if (product.main_product) {
            rows.value.splice(insertAt, 0, blank);
            const target = placeSubItem(insertAt, product, quantity);
            accessoryOf[rows.value[target]._key] = row._key;

            return;
        }

        const existing = rows.value.find((other) => other !== row && isItem(other) && Number(other.product_id) === Number(product.id) && sameGroup(other));

        if (existing) {
            if (existing.pricing_type !== 'weight') existing.quantity = round4(num(existing.quantity) + quantity);

            return;
        }

        Object.assign(blank, {
            product_id: product.id,
            product_label: product.label,
            product_search: product.label,
            barcode: product.barcode ?? null,
            description: product.description ?? null,
            unit_price: product.unit_price ?? '0.0000',
            pricing_type: product.pricing_type || 'simple',
            length: product.length ?? null,
            has_sub_products: Boolean(product.has_sub_products),
            quantity,
        });

        rows.value.splice(insertAt, 0, blank);
        accessoryOf[blank._key] = row._key;
    }

    function addAccessory(rowIndex: number, productId: number): void {
        const suggestion = pendingSuggestions(rows.value[rowIndex]).find((item) => Number(item.product.id) === Number(productId));

        if (config.value.editable && suggestion) insertAccessory(rowIndex, suggestion);
    }

    function addAllAccessories(rowIndex: number): void {
        const key = rows.value[rowIndex]?._key;

        if (!config.value.editable || !key) return;

        for (const suggestion of pendingSuggestions(rows.value[rowIndex])) {
            // Placing a sub-item may remove a row, so the item's row is found again by its key.
            const index = rows.value.findIndex((row) => row._key === key);

            if (index !== -1) insertAccessory(index, suggestion);
        }
    }

    function dismissSuggestions(row: GridRow): void {
        delete suggestions.value[row._key];
    }

    /* ---------- prices of a new party ---------- */

    const reprice = ref<Record<string, string> | null>(null);

    /** The party changed: compare the lines' prices with the new party's and ask before changing them. */
    async function offerReprice(): Promise<void> {
        reprice.value = null;
        const c = config.value;

        if (!c.editable || !c.pricesEditable || !c.showPrices) return;

        const lines = rows.value.filter((row) => isItem(row) && row.product_id);

        if (lines.length === 0) return;

        try {
            const prices = await api.prices(lines.map((row) => Number(row.product_id)));
            const differs = lines.some((row) => prices[String(row.product_id)] !== undefined && num(prices[String(row.product_id)]) !== num(row.unit_price));

            reprice.value = differs ? prices : null;
        } catch {
            reprice.value = null;
        }
    }

    function applyReprice(): void {
        const prices = reprice.value;

        if (!prices) return;

        for (const row of rows.value) {
            if (row.product_id && prices[String(row.product_id)] !== undefined) row.unit_price = prices[String(row.product_id)];
        }

        reprice.value = null;
    }

    watch(
        () => context().partyId,
        (party, previous) => {
            if (String(party ?? '') !== String(previous ?? '')) void offerReprice();
        },
    );

    /* ---------- stock and cost of the focused line ---------- */

    const stock = ref<StockInfo | null>(null);
    let stockTarget: { row: number; piece: number | null } | null = null;
    let stockKey: string | null = null;

    function noteStockFocus(event: FocusEvent): void {
        const cell = (event.target as HTMLElement | null)?.closest?.('[data-pcell], [data-cell]') as HTMLElement | null;

        if (!cell) return;

        const parts = (cell.dataset.pcell ?? cell.dataset.cell ?? '').split(':').map(Number);
        stockTarget = { row: parts[0], piece: cell.dataset.pcell ? parts[1] : null };
        void refreshStock();
    }

    async function refreshStock(): Promise<void> {
        if (!stockTarget) return;

        const row = rows.value[stockTarget.row];
        const line = stockTarget.piece === null ? row : row?.pieces?.[stockTarget.piece];
        const warehouseId = context().warehouseId ?? null;
        const key = line?.product_id ? `${line.product_id}|${warehouseId ?? ''}` : null;

        if (key === stockKey) return;

        stockKey = key;

        if (!key || !line?.product_id) {
            stock.value = null;

            return;
        }

        try {
            const info = await api.stock(Number(line.product_id), warehouseId);

            if (stockKey === key) stock.value = info;
        } catch {
            stock.value = null;
        }
    }

    watch(() => context().warehouseId, () => void refreshStock());

    onBeforeUnmount(() => {
        clearTimeout(totalsTimer);
        clearTimeout(searchTimer);
        clearTimeout(pieceTimer);
        totalsRequest?.abort();
        searchRequest?.abort();
        pieceRequest?.abort();
    });

    return {
        rows,
        config,
        api,
        totals,
        notices,
        rowNotices,
        // rows
        itemNumber,
        groupCount,
        groupTotals,
        addRow,
        addItemToGroup,
        removeRow,
        addPiece,
        openSubItems,
        removePiece,
        setDiscountMode,
        normalizeDiscount,
        missingMeasure,
        clampQuantity,
        clampPieceCount,
        // keyboard
        onCellKey,
        onPieceCellKey,
        // search
        search,
        pieceSearch,
        queueSearch,
        setSearchMode,
        chooseResult,
        onProductFocus,
        onProductBlur,
        onProductKey,
        queuePieceSearch,
        onPieceFocus,
        onPieceBlur,
        onPieceProductKey,
        pieceSearchOpenFor,
        choosePieceProduct,
        // pieces of a changed main item
        pieceChoices,
        clearPieces,
        matchPieces,
        dismissPieceChoice,
        // barcode
        scan,
        scanBarcode,
        pickScanned,
        // accessories
        pendingSuggestions,
        accessoryQuantity,
        addAccessory,
        addAllAccessories,
        dismissSuggestions,
        // prices
        reprice,
        applyReprice,
        // stock
        stock,
        noteStockFocus,
    };
}

export type ItemsGridState = ReturnType<typeof useItemsGrid>;
