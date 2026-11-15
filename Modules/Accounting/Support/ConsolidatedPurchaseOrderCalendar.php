<?php

namespace Modules\Accounting\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * كل التواريخ المرتبطة بطلبيات الشراء التجميعية مجمّعة في مصدر واحد للتقويم:
 * تاريخ الطلبية، التسليم المتوقع، عروض الاسعار، استحقاقات الدفعات والاعتمادات،
 * إعادة التخمين، وتذكيرات الملاحظات.
 *
 * الالوان بصيغة hex لا كأصناف Tailwind، لان اسماء الاصناف المولّدة ديناميكياً
 * لا تصل الى ملف الـ CSS المبني.
 */
class ConsolidatedPurchaseOrderCalendar
{
    /**
     * وصف كل نوع حدث: العنوان الظاهر ولونه.
     *
     * @return array<string, array{label: string, color: string, tint: string}>
     */
    public static function types(): array
    {
        return [
            'order_date' => ['label' => __('Order Date'), 'color' => '#4f46e5', 'tint' => '#eef2ff'],
            'expected_delivery_date' => ['label' => __('Expected Delivery Date'), 'color' => '#d97706', 'tint' => '#fffbeb'],
            'offer_date' => ['label' => __('Price Offer'), 'color' => '#0284c7', 'tint' => '#f0f9ff'],
            'payment_due' => ['label' => __('Payment Due'), 'color' => '#e11d48', 'tint' => '#fff1f2'],
            'bank_credit_due' => ['label' => __('Bank Credit Due'), 'color' => '#7c3aed', 'tint' => '#f5f3ff'],
            'reappraisal_date' => ['label' => __('Reappraisal Date'), 'color' => '#ea580c', 'tint' => '#fff7ed'],
            'note_reminder' => ['label' => __('Note Reminder'), 'color' => '#059669', 'tint' => '#ecfdf5'],
        ];
    }

    /**
     * الاحداث الواقعة ضمن المدى، مجمّعة حسب اليوم (Y-m-d) ومرتبة داخل كل يوم.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function eventsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $events = array_merge(
            self::orderEvents($from, $to),
            self::childEvents($from, $to),
        );

        $grouped = [];

        foreach ($events as $event) {
            $grouped[$event['date']][] = $event;
        }

        foreach ($grouped as $date => $dayEvents) {
            usort($dayEvents, fn (array $a, array $b) => [$a['type'], $a['reference']] <=> [$b['type'], $b['reference']]);
            $grouped[$date] = $dayEvents;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * تاريخ الطلبية والتسليم المتوقع من جدول الطلبيات نفسه.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function orderEvents(Carbon $from, Carbon $to): array
    {
        $orders = DB::table(self::table('consolidated_purchase_orders').' as orders')
            ->leftJoin('users as clients', 'clients.id', '=', 'orders.client_id')
            ->whereNull('orders.deleted_at')
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('orders.order_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('orders.expected_delivery_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->get([
                'orders.id',
                'orders.reference_no',
                'orders.order_date',
                'orders.expected_delivery_date',
                'clients.name as client_name',
            ]);

        $events = [];

        foreach ($orders as $order) {
            foreach (['order_date', 'expected_delivery_date'] as $field) {
                $date = self::dateKey($order->{$field});

                if ($date === null || $date < $from->toDateString() || $date > $to->toDateString()) {
                    continue;
                }

                $events[] = self::event($field, $date, (int) $order->id, $order->reference_no, $order->client_name);
            }
        }

        return $events;
    }

    /**
     * تواريخ السجلات التابعة للطلبية (عروض، دفعات، اعتمادات، تخمين، تذكيرات).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function childEvents(Carbon $from, Carbon $to): array
    {
        $sources = [
            'offer_date' => ['consolidated_purchase_order_price_offers', 'offer_date'],
            'payment_due' => ['consolidated_purchase_order_payments', 'due_date'],
            'bank_credit_due' => ['consolidated_purchase_order_bank_credits', 'due_date'],
            'reappraisal_date' => ['consolidated_purchase_order_reappraisals', 'reappraisal_date'],
            'note_reminder' => ['consolidated_purchase_order_notes', 'reminder_date'],
        ];

        $events = [];

        foreach ($sources as $type => [$table, $column]) {
            $rows = DB::table(self::table($table).' as source')
                ->join(
                    self::table('consolidated_purchase_orders').' as orders',
                    'orders.id',
                    '=',
                    'source.consolidated_purchase_order_id'
                )
                ->leftJoin('users as clients', 'clients.id', '=', 'orders.client_id')
                ->whereNull('orders.deleted_at')
                ->whereNull('source.deleted_at')
                ->whereBetween('source.'.$column, [$from->toDateString(), $to->toDateString()])
                ->get([
                    'orders.id',
                    'orders.reference_no',
                    'source.'.$column.' as event_date',
                    'clients.name as client_name',
                ]);

            foreach ($rows as $row) {
                $date = self::dateKey($row->event_date);

                if ($date === null) {
                    continue;
                }

                $events[] = self::event($type, $date, (int) $row->id, $row->reference_no, $row->client_name);
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $type, string $date, int $orderId, ?string $reference, ?string $client): array
    {
        $meta = self::types()[$type];

        return [
            'type' => $type,
            'date' => $date,
            'label' => $meta['label'],
            'color' => $meta['color'],
            'tint' => $meta['tint'],
            'reference' => $reference ?? '—',
            'client' => $client,
            'url' => route('accounting.consolidated-purchase-orders.edit', $orderId),
        ];
    }

    private static function dateKey(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private static function table(string $name): string
    {
        return config('accounting.table_prefix').$name;
    }
}
