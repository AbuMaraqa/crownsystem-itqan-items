<?php

namespace Modules\Accounting\Support;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Items\Support\ProductSearch;

/**
 * قواعد المرتجع الواحدة لمرتجع البيع ومرتجع الشراء:
 *
 * - صنف الوزن يُرجع اصنافاً فرعية بعدد قطع، لكل قطعة حد اقصى = قطع الفاتورة − ما أُرجع منها
 *   في مرتجعات مرحّلة اخرى. القطع تتحرك بعددها، والكمية (كغم) تتحرك على الصنف الرئيسي؛ سطر
 *   فاتورة بلا وزن فعلي تُرجع قطعه بلا كغم.
 * - الكمية المرتجعة (كغم) = الوزن النظري للقطع المرتجعة × معامل الوزن، والمعامل = الكغم
 *   المفوترة ÷ الوزن النظري لقطع الفاتورة؛ فسطر وُزن بالميزان يُرجع بنسبته، وارجاع كل قطعه
 *   يساوي الكمية المفوترة تماماً.
 * - صنف الكمية (او سطر وزن قديم بلا قطع) يُرجع بكمية لا تتجاوز القابل للارجاع.
 */
class ReturnPieces
{
    private const EPSILON = 0.00005;

    /**
     * قطع سطر المرتجع من قطع سطر الفاتورة والاعداد المدخلة؛ القطعة بعدد صفر لا تُرجع.
     *
     * @param  Collection<int, Model>  $sourcePieces  قطع سطر الفاتورة
     * @param  array<int, float>  $returned  معرف قطعة الفاتورة => ما أُرجع منها في مرتجعات مرحّلة اخرى
     * @param  array<int, array<string, mixed>>  $input  قطع المرتجع كما ارسلها الجدول
     * @param  string  $sourceKey  عمود ربط قطعة المرتجع بقطعة الفاتورة
     * @return array<int, array<string, mixed>>
     *
     * @throws DomainException
     */
    public static function normalize(Collection $sourcePieces, array $returned, array $input, string $sourceKey, string $itemLabel): array
    {
        $inputBySource = collect($input)
            ->filter(fn (array $piece): bool => filled($piece[$sourceKey] ?? null))
            ->keyBy(fn (array $piece): int => (int) $piece[$sourceKey]);
        $pieces = [];

        foreach ($sourcePieces->values() as $index => $source) {
            $entered = $inputBySource->get($source->id);
            $count = $entered ? round(max((float) str_replace([',', ' '], '', (string) ($entered['piece_count'] ?? 0)), 0), 4) : 0.0;

            if ($count <= 0) {
                continue;
            }

            $available = self::available($source, $returned);

            if ($count > $available + self::EPSILON) {
                throw new DomainException(__(':item / :sub: returned pieces (:count) exceed the returnable pieces (:remaining).', [
                    'item' => $itemLabel,
                    'sub' => $source->description ?: ProductSearch::label($source->product_id),
                    'count' => $count + 0,
                    'remaining' => $available + 0,
                ]));
            }

            $pieces[] = [
                'id' => $entered['id'] ?? null,
                $sourceKey => $source->id,
                'product_id' => $source->product_id,
                'barcode' => $source->barcode,
                'description' => $source->description,
                'piece_count' => $count,
                'piece_length' => (float) $source->piece_length,
                'weight_per_meter' => (float) $source->weight_per_meter,
                'total_weight' => WeightPieces::weight(['piece_count' => $count, 'piece_length' => $source->piece_length, 'weight_per_meter' => $source->weight_per_meter]),
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ];
        }

        return $pieces;
    }

    /**
     * كل القطع القابلة للارجاع من سطر الفاتورة (مرتجع جديد بلا سطور).
     *
     * @param  Collection<int, Model>  $sourcePieces
     * @param  array<int, float>  $returned
     * @return array<int, array<string, mixed>>
     */
    public static function allAvailable(Collection $sourcePieces, array $returned, string $sourceKey): array
    {
        return $sourcePieces
            ->map(fn ($source): array => [$sourceKey => $source->id, 'piece_count' => self::available($source, $returned)])
            ->filter(fn (array $piece): bool => $piece['piece_count'] > 0)
            ->values()
            ->all();
    }

    /**
     * كغم مفوترة لكل كغم نظري في سطر الفاتورة.
     */
    public static function weightFactor(Model $invoiceItem): float
    {
        $theoretical = (float) $invoiceItem->pieces->sum('total_weight');

        return $theoretical > 0 ? round((float) $invoiceItem->quantity / $theoretical, 8) : 1.0;
    }

    /**
     * الكمية المرتجعة (كغم) لقطع مرتجعة من سطر فاتورة.
     *
     * @param  array<int, array<string, mixed>>  $pieces  قطع بعد normalize()
     */
    public static function quantity(Model $invoiceItem, array $pieces): float
    {
        $theoretical = round(array_sum(array_column($pieces, 'total_weight')), 4);
        $invoiced = (float) $invoiceItem->quantity;

        // ارجاع كل القطع = الكمية المفوترة تماماً، بلا فرق تقريب.
        $all = abs($theoretical - (float) $invoiceItem->pieces->sum('total_weight')) <= self::EPSILON;

        return $all ? round($invoiced, 4) : round($theoretical * self::weightFactor($invoiceItem), 4);
    }

    /**
     * صفوف جدول الاصناف الموحد لصفحة المرتجع: كل سطور الفاتورة القابلة للارجاع (او الموجودة
     * في المرتجع) باسعارها، مع ما أُدخل في المرتجع وحد اقصى لكل كمية وكل قطعة.
     *
     * @param  Collection<int, Model>  $invoiceItems  سطور الفاتورة بعلاقتي product و pieces
     * @param  Collection<int, Model>  $returnItems  سطور المرتجع بعلاقة pieces
     * @param  array<int, float>  $returnable  معرف سطر الفاتورة => القابل للارجاع (كغم او كمية)
     * @param  array<int, float>  $returnedPieces  معرف قطعة الفاتورة => ما أُرجع منها في مرتجعات مرحّلة اخرى
     * @return array<int, array<string, mixed>>
     */
    public static function gridRows(Collection $invoiceItems, Collection $returnItems, array $returnable, array $returnedPieces, string $itemKey, string $pieceKey): array
    {
        $returnItems = $returnItems->keyBy($itemKey);
        $invoiceItems = $invoiceItems
            ->filter(fn ($item) => ($returnable[$item->id] ?? 0) > 0
                || $returnItems->has($item->id)
                || $item->pieces->contains(fn ($piece) => self::available($piece, $returnedPieces) > 0))
            ->values();

        return collect(ItemsGridState::rows($invoiceItems))
            ->map(function (array $row, int $index) use ($invoiceItems, $returnItems, $returnable, $returnedPieces, $itemKey, $pieceKey): array {
                $invoiceItem = $invoiceItems[$index];
                $returnItem = $returnItems->get($invoiceItem->id);
                $hasPieces = $row['pricing_type'] === 'weight' && $invoiceItem->pieces->isNotEmpty();
                $returnPieces = $returnItem ? $returnItem->pieces->keyBy($pieceKey) : collect();

                $row = array_merge($row, [
                    '_key' => 'invoice-item-'.$invoiceItem->id,
                    'id' => $returnItem?->id,
                    $itemKey => $invoiceItem->id,
                    // سطر وزن قديم بلا قطع يُرجع بالكغم كصنف كمية.
                    'pricing_type' => $hasPieces ? 'weight' : 'simple',
                    'quantity' => $returnItem ? (float) $returnItem->quantity : 0,
                    // القابل للارجاع محسوب بلا هذا المرتجع، فهو الحد الاقصى لكميته.
                    'max_quantity' => round((float) ($returnable[$invoiceItem->id] ?? 0), 4),
                    'actual_weight' => null,
                    'weight_factor' => $hasPieces ? self::weightFactor($invoiceItem) : 1,
                    'discount_amount' => 0,
                    'line_total' => $returnItem ? (float) $returnItem->line_total : 0,
                    'notes' => $returnItem?->notes,
                ]);

                $row['pieces'] = collect($row['pieces'])->map(function (array $piece) use ($returnPieces, $returnedPieces, $pieceKey, $invoiceItem): array {
                    $source = $invoiceItem->pieces->firstWhere('id', $piece['id']);
                    $returnPiece = $returnPieces->get($piece['id']);
                    $count = $returnPiece ? (float) $returnPiece->piece_count : 0;

                    return array_merge($piece, [
                        '_key' => 'invoice-piece-'.$piece['id'],
                        'id' => $returnPiece?->id,
                        $pieceKey => $piece['id'],
                        'piece_count' => $count,
                        'total_weight' => WeightPieces::weight(['piece_count' => $count, 'piece_length' => $piece['piece_length'], 'weight_per_meter' => $piece['weight_per_meter']]),
                        'max_piece_count' => self::available($source, $returnedPieces),
                    ]);
                })->values()->all();

                $row['total_weight'] = round(array_sum(array_column($row['pieces'], 'total_weight')), 4);

                return $row;
            })
            ->all();
    }

    /**
     * @param  array<int, float>  $returned
     */
    private static function available(Model $source, array $returned): float
    {
        return max(round((float) $source->piece_count - (float) ($returned[$source->id] ?? 0), 4), 0);
    }
}
