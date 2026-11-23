<?php

namespace Modules\Items\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Order;
use Modules\Items\Entities\Product;

/**
 * كتابة طلبات المتجر من لوحة التحكم: الطلب وسطوره معاً.
 *
 * سعر السطر سعر الصنف الاساسي (base_price) وقت اضافته؛ السطر المحفوظ الذي لم يتغير صنفه
 * يبقى بسعره. المجموع الفرعي والاجمالي (مع رسوم التوصيل) يُحسبان هنا، لا يؤخذان من الواجهة.
 */
class OrderService
{
    private const ORDER_FIELDS = [
        'user_id', 'branch_id', 'city_id', 'district_id', 'delivery_fee', 'status', 'payment_method',
        'payment_status', 'delivery_address', 'contact_phone', 'notes',
    ];

    /**
     * @param  array<string, mixed>  $data  حقول الطلب و items: [{product_id, quantity}]
     */
    public function create(array $data, ?int $createdBy): Order
    {
        return DB::transaction(function () use ($data, $createdBy): Order {
            $lines = $this->lines($data['items'] ?? [], collect());

            $order = Order::query()->create([
                ...Arr::only($data, self::ORDER_FIELDS),
                ...$this->totals($lines, $data['delivery_fee'] ?? 0),
                'order_number' => $this->nextNumber(),
                'created_by' => $createdBy,
            ]);

            $order->items()->createMany($lines);

            return $order;
        });
    }

    /**
     * السطور التي حُذفت من النموذج تُحذف، والباقي يُحدَّث بمعرّفه او يُضاف.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $saved = $order->items()->get()->keyBy('id');
            $lines = $this->lines($data['items'] ?? [], $saved);

            $order->update([
                ...Arr::only($data, self::ORDER_FIELDS),
                ...$this->totals($lines, $data['delivery_fee'] ?? 0),
            ]);

            $keep = collect($lines)->pluck('id')->filter()->all();
            $order->items()->whereNotIn('id', $keep)->delete();

            foreach ($lines as $line) {
                $order->items()->updateOrCreate(['id' => $line['id'] ?? null], Arr::except($line, 'id'));
            }

            return $order;
        });
    }

    /**
     * حذف الطلب من الجدول نهائياً، كما كان في الصفحة القديمة.
     */
    public function delete(Order $order): void
    {
        $order->forceDelete();
    }

    /**
     * الحذف الجماعي حذف ناعم، كما كان في الصفحة القديمة.
     */
    public function softDelete(Order $order): void
    {
        $order->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  \Illuminate\Support\Collection<int, \Modules\Items\Entities\OrderItem>  $saved
     * @return list<array<string, mixed>>
     */
    private function lines(array $items, $saved): array
    {
        $prices = Product::query()->whereKey(array_column($items, 'product_id'))->pluck('base_price', 'id');

        return collect($items)->map(function (array $item) use ($prices, $saved): array {
            $existing = isset($item['id']) ? $saved->get((int) $item['id']) : null;
            $sameProduct = $existing && (int) $existing->product_id === (int) $item['product_id'];
            $price = $sameProduct ? (float) $existing->price : (float) ($prices[$item['product_id']] ?? 0);
            $quantity = (float) $item['quantity'];

            return [
                'id' => $existing?->id,
                'product_id' => (int) $item['product_id'],
                'quantity' => $quantity,
                'price' => $price,
                'total_price' => $price * $quantity,
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{sub_total: float, total: float}
     */
    private function totals(array $lines, mixed $deliveryFee): array
    {
        $subTotal = round(array_sum(array_column($lines, 'total_price')), 2);

        return ['sub_total' => $subTotal, 'total' => round($subTotal + (float) $deliveryFee, 2)];
    }

    /**
     * رقم الطلب كما في الصفحة القديمة: السنة واليوم والشهر ثم الساعة والدقيقة ثم التسلسل.
     */
    private function nextNumber(): string
    {
        $now = now();
        $sequence = Order::withTrashed()->count() + 1;

        do {
            $number = $now->format('ydm').$now->format('Hi').$sequence++;
        } while (Order::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }
}
