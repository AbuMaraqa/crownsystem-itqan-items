import { emptyRow } from './productForm';
import type { AttributeChoice, ProductForm, ProductRowFields, VariationRow } from './types';

/** The parent's fields a variation copies. Never its name, slug, attributes or images. */
const INHERITED = [
    'short_description', 'description', 'barcode',
    'base_price',
    'max_limit', 'min_limit_1', 'min_limit_2',
    'category_id', 'meta_title', 'meta_description', 'meta_keywords',
] as const satisfies readonly (keyof ProductRowFields)[];

/** A copy, so a row and its parent never share one array. */
function inherited(parent: ProductRowFields): Partial<ProductRowFields> {
    const copy: Record<string, unknown> = {};

    for (const field of INHERITED) {
        const value = parent[field];
        copy[field] = Array.isArray(value) ? [...value] : value;
    }

    return copy as Partial<ProductRowFields>;
}

/** Every combination of the chosen values, one value per attribute. */
export function cartesian(lists: number[][]): number[][] {
    return lists.reduce<number[][]>((rows, values) => rows.flatMap((row) => values.map((value) => [...row, value])), [[]]);
}

/** Identifies a combination whatever order its values are in. */
export function variationKey(valueIds: number[]): string {
    return [...valueIds].sort((a, b) => a - b).join('-');
}

function nameOf(parentName: string, valueIds: number[], names: Map<number, string>): string {
    return [parentName, ...valueIds.map((id) => names.get(id) ?? String(id))].filter(Boolean).join(' - ');
}

/**
 * The rows for the current selection. A combination that already has a row keeps it with its edits
 * (and its id); a new one starts from the parent's data. The name follows the parent, and the slug is
 * made again from it on the server (it transliterates Arabic), as the old page did.
 */
export function generateVariations(form: ProductForm, attributes: AttributeChoice[], removed: Map<string, VariationRow>): VariationRow[] {
    const names = new Map(attributes.flatMap((attribute) => attribute.values.map((value) => [value.id, value.name] as const)));

    const lists = attributes
        .map((attribute) => {
            const chosen = form.attributes[attribute.id] ?? [];
            return attribute.values.map((value) => value.id).filter((id) => chosen.includes(id));
        })
        .filter((list) => list.length > 0);

    const current = new Map(form.variations.map((row) => [variationKey(row.attribute_values), row]));
    const combinations = lists.length ? cartesian(lists) : [];
    const keep = new Set(combinations.map(variationKey));

    current.forEach((row, key) => {
        if (!keep.has(key)) removed.set(key, row);
    });

    return combinations.map((valueIds) => {
        const key = variationKey(valueIds);
        const existing = current.get(key) ?? removed.get(key);
        const name = nameOf(form.name, valueIds, names);

        if (existing) {
            return { ...existing, name, slug: existing.name === name ? existing.slug : '', attribute_values: valueIds };
        }

        return { ...emptyRow(), ...inherited(form), id: null, attribute_values: valueIds, name, slug: '' };
    });
}

/** Copies the parent's data into every row; each row keeps its name, slug, attributes and images. */
export function inheritParentData(form: ProductForm): VariationRow[] {
    return form.variations.map((row) => ({ ...row, ...inherited(form) }));
}
