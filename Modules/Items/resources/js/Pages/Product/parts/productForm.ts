import type { ProductForm, ProductRowFields, VariationRow } from './types';

/** An empty set of the fields an item and a variation share. */
export function emptyRow(): ProductRowFields {
    return {
        name: '',
        slug: '',
        short_description: '',
        description: '',
        sku: '',
        barcode: '',
        base_price: '',
        sale_price: '',
        cost_price: '',
        wholesale_price: '',
        discount_type: null,
        discount_value: '',
        discount_start_date: '',
        discount_end_date: '',
        max_limit: '',
        min_limit_1: '',
        min_limit_2: '',
        category_id: [],
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
        image: null,
        gallery: [],
        images: [],
        gallery_images: [],
        removed_image: [],
        removed_gallery: [],
    };
}

/** A new item. */
export function emptyProduct(): ProductForm {
    return {
        ...emptyRow(),
        weight_per_meter: '',
        length: '',
        pricing_type: 'simple',
        item_type_profile_id: null,
        bundle_count: '',
        profile_thickness: null,
        is_open_profile: null,
        area: '',
        item_type: 'goods',
        service_kind: 'general',
        service_cost_source: 'internal',
        revenue_account_id: null,
        cost_account_id: null,
        main_product_id: null,
        site_area: '',
        color: '',
        brand_id: null,
        unit_id: null,
        tags: [],
        labels: [],
        manufacturers: [],
        branches: [],
        addons: [],
        accessories: [],
        cutting_recipes: [],
        variations: [],
        attributes: {},
    };
}

/** The saved row as a form row: the server sends no file fields or removal lists. */
export function withUploadState<T extends object>(row: T): T & Pick<ProductRowFields, 'image' | 'gallery' | 'removed_image' | 'removed_gallery'> {
    return { ...row, image: null, gallery: [], removed_image: [], removed_gallery: [] };
}

export const isWeight = (form: Pick<ProductForm, 'pricing_type'>): boolean => form.pricing_type === 'weight';

export const isService = (form: Pick<ProductForm, 'item_type'>): boolean => form.item_type === 'service';

/** A piece: a weight item under a main item. It has no price. */
export const isPiece = (form: Pick<ProductForm, 'pricing_type' | 'item_type' | 'main_product_id'>): boolean =>
    isWeight(form) && !isService(form) && form.main_product_id !== null && form.main_product_id !== '';

function isFile(value: unknown): value is File {
    return typeof File !== 'undefined' && value instanceof File;
}

function files(value: File | File[] | null): File[] {
    return (Array.isArray(value) ? value : [value]).filter(isFile);
}

/** What a weight item does not show, so the form does not send it (the server drops it too). */
const COMMERCE = ['tags', 'labels', 'brand_id', 'manufacturers', 'addons', 'variations', 'meta_title', 'meta_description', 'meta_keywords'] as const;

/**
 * The request body: everything but the files as one JSON field (see ProductRequest), the files under
 * their own form paths so a file's error comes back on its field.
 */
export function toRequestBody(data: ProductForm, extra: Record<string, unknown> = {}): Record<string, unknown> {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { image, gallery, images: _images, gallery_images: _galleryImages, attributes: _attributes, variations, ...rest } = data;
    const variationFiles: Record<number, { image?: File; gallery?: File[] }> = {};

    const rows = variations.map((row: VariationRow, index: number) => {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { image: rowImage, gallery: rowGallery, images: _rowImages, gallery_images: _rowGallery, ...fields } = row;
        const [first] = files(rowImage);
        const many = files(rowGallery);

        if (first || many.length) {
            variationFiles[index] = { ...(first ? { image: first } : {}), ...(many.length ? { gallery: many } : {}) };
        }

        return fields;
    });

    const payload: Record<string, unknown> = { ...rest, variations: rows };

    if (isWeight(data)) {
        for (const key of COMMERCE) delete payload[key];
    }

    const [mainImage] = files(image);

    return {
        ...extra,
        payload: JSON.stringify(payload),
        ...(mainImage ? { image: mainImage } : {}),
        gallery: files(gallery),
        variations: variationFiles,
    };
}
