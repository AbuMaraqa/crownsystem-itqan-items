<script setup lang="ts">
import { computed, inject } from 'vue';

import { t } from '@/lib/i18n';
import { CircleAlert, Plus, X } from '@/lib/icons';

import { pieceInput, pieceInputMissing, pieceInputOk } from './classes';
import GridSearchDropdown from './GridSearchDropdown.vue';
import { gridKey, money } from './useItemsGrid';

/**
 * The sub-items (pieces) of a main weight item, always open under its line as in the old system:
 * # | Item | Barcode | (max) | Piece count | Piece length | Weight per meter | Total weight | Source item.
 * A piece weighs count × length × weight per meter; the line's weight is their sum.
 */
const props = defineProps<{ rowIndex: number }>();

const grid = inject(gridKey)!;
const row = computed(() => grid.rows.value[props.rowIndex]);
const config = grid.config;
const picked = computed(() => row.value.pieces.filter((piece) => piece.product_id).length);

const measures = ['piece_count', 'piece_length', 'weight_per_meter'] as const;
const measureLabels: Record<(typeof measures)[number], string> = {
    piece_count: 'Piece Count',
    piece_length: 'Piece Length',
    weight_per_meter: 'Weight per Meter',
};

const readonlyMeasure = (measure: (typeof measures)[number]): boolean =>
    measure === 'piece_count' ? !config.value.quantitiesEditable : !config.value.editable;
</script>

<template>
    <div class="border-s-2 border-brand-300 bg-brand-25 px-3 py-3 dark:border-brand-500/40 dark:bg-brand-500/[0.04]">
        <div class="mb-2 flex items-center justify-between gap-3">
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                {{ t('Sub Items') }} <span class="text-muted tabular-nums">({{ picked }})</span>
            </span>
            <button
                v-if="config.editable"
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-card px-2.5 py-1 text-xs font-medium text-brand-600 transition-colors hover:bg-brand-50 dark:border-brand-500/30 dark:text-brand-400 dark:hover:bg-brand-500/10"
                @click="grid.openSubItems(rowIndex, true)"
            >
                <Plus class="size-3.5" />{{ t('Add Sub-item') }}
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[46rem] border-collapse text-xs">
                <thead>
                    <tr class="text-muted">
                        <th class="w-12 px-2 py-1 text-center font-medium">#</th>
                        <th class="px-2 py-1 text-start font-medium">{{ t('Item') }}</th>
                        <th class="w-28 px-2 py-1 text-start font-medium">{{ t('Barcode') }}</th>
                        <th v-if="config.quantityLimitLabel" class="w-24 px-2 py-1 text-end font-medium">{{ config.quantityLimitLabel }}</th>
                        <th v-for="measure in measures" :key="measure" class="w-24 px-2 py-1 text-end font-medium">{{ t(measureLabels[measure]) }}</th>
                        <th class="w-24 px-2 py-1 text-end font-medium">{{ t('Total Weight') }}</th>
                        <th class="w-40 px-2 py-1 text-start font-medium">{{ t('Source Item') }}</th>
                        <th class="w-9 px-1 py-1"><span class="sr-only">{{ t('Remove') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(piece, pieceIndex) in row.pieces" :key="piece._key" class="border-t border-line">
                        <td class="px-2 py-1 text-center text-muted tabular-nums" dir="ltr">{{ grid.itemNumber(rowIndex) }}.{{ pieceIndex + 1 }}</td>

                        <td class="px-2 py-1">
                            <input
                                v-model="piece.product_label"
                                type="text"
                                autocomplete="off"
                                :data-pcell="`${rowIndex}:${pieceIndex}:output`"
                                :readonly="!config.editable"
                                :placeholder="row.has_sub_products ? t('Pick a sub-item') : ''"
                                :class="[pieceInput, pieceInputOk]"
                                @input="grid.queuePieceSearch(rowIndex, pieceIndex, 'output', ($event.target as HTMLInputElement).value)"
                                @focus="grid.onPieceFocus(rowIndex, pieceIndex, 'output')"
                                @blur="grid.onPieceBlur()"
                                @keydown="grid.onPieceProductKey($event, rowIndex, pieceIndex, 'output')"
                            />
                            <GridSearchDropdown
                                compact
                                :open="grid.pieceSearchOpenFor(rowIndex, pieceIndex, 'output')"
                                :anchor="grid.pieceSearch.anchor"
                                :results="grid.pieceSearch.results"
                                :highlight="grid.pieceSearch.highlight"
                                :loading="grid.pieceSearch.loading"
                                @choose="grid.choosePieceProduct(rowIndex, pieceIndex, 'output', $event)"
                                @hover="grid.pieceSearch.highlight = $event"
                            />
                        </td>

                        <td class="px-2 py-1 font-mono text-muted" dir="ltr">{{ piece.barcode || '—' }}</td>

                        <td v-if="config.quantityLimitLabel" class="px-2 py-1 text-end text-muted tabular-nums" dir="ltr">{{ piece.max_piece_count ?? '—' }}</td>

                        <td v-for="measure in measures" :key="measure" class="px-2 py-1">
                            <input
                                v-model="piece[measure]"
                                type="text"
                                inputmode="decimal"
                                :data-pcell="`${rowIndex}:${pieceIndex}:${measure}`"
                                :readonly="readonlyMeasure(measure)"
                                :title="grid.missingMeasure(piece, measure) ? t('The sub-item weight is count × piece length × weight per meter; enter this value.') : undefined"
                                :aria-invalid="grid.missingMeasure(piece, measure)"
                                :class="[pieceInput, 'text-end tabular-nums', grid.missingMeasure(piece, measure) ? pieceInputMissing : pieceInputOk]"
                                @focus="($event.target as HTMLInputElement).select()"
                                @input="measure === 'piece_count' && grid.clampPieceCount(piece)"
                                @keydown="grid.onPieceCellKey($event, rowIndex, pieceIndex, measure)"
                            />
                        </td>

                        <td class="px-2 py-1 text-end font-semibold tabular-nums text-gray-700 dark:text-gray-200" dir="ltr">{{ money(piece.total_weight) }}</td>

                        <!-- The cutting recipe's source item: optional, outside the Enter order. -->
                        <td class="px-2 py-1">
                            <input
                                v-model="piece.source_product_label"
                                type="text"
                                autocomplete="off"
                                :data-pcell="`${rowIndex}:${pieceIndex}:source`"
                                :readonly="!config.editable"
                                :class="[pieceInput, pieceInputOk, 'text-gray-600 dark:text-gray-300']"
                                @input="grid.queuePieceSearch(rowIndex, pieceIndex, 'source', ($event.target as HTMLInputElement).value)"
                                @focus="grid.onPieceFocus(rowIndex, pieceIndex, 'source')"
                                @blur="grid.onPieceBlur()"
                                @keydown="grid.onPieceProductKey($event, rowIndex, pieceIndex, 'source')"
                            />
                            <GridSearchDropdown
                                compact
                                :open="grid.pieceSearchOpenFor(rowIndex, pieceIndex, 'source')"
                                :anchor="grid.pieceSearch.anchor"
                                :results="grid.pieceSearch.results"
                                :highlight="grid.pieceSearch.highlight"
                                :loading="grid.pieceSearch.loading"
                                @choose="grid.choosePieceProduct(rowIndex, pieceIndex, 'source', $event)"
                                @hover="grid.pieceSearch.highlight = $event"
                            />
                        </td>

                        <td class="px-1 py-1 text-center">
                            <button
                                v-if="config.editable"
                                type="button"
                                class="inline-flex size-7 items-center justify-center rounded-md text-gray-400 transition-colors hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10"
                                :aria-label="t('Remove')"
                                :title="t('Remove')"
                                @click="grid.removePiece(rowIndex, pieceIndex)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </td>
                    </tr>

                    <!-- A main item is not complete without sub-items: its weight is the sum of theirs. -->
                    <tr v-if="picked === 0">
                        <td :colspan="9 + (config.quantityLimitLabel ? 1 : 0)" class="px-2 py-2.5 text-center text-xs font-medium text-warning-700 dark:text-warning-400">
                            <span class="inline-flex items-center gap-1.5"><CircleAlert class="size-4" />{{ t('Pick the sub-items of this main item; their weights add up to its total weight.') }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
