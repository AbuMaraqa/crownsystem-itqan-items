<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Http\Requests\Admin\ReportRequest;
use Modules\Accounting\Services\CustomerHistoryService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Entities\User;
use Modules\Items\Support\ProductSearch;

/**
 * سجل الزبون: ملخص وكشف حساب ومستندات وآخر اسعار الاصناف وحركتها لطرف واحد.
 * يُحسب التبويب المفتوح فقط؛ كل الارقام من CustomerHistoryService.
 */
class CustomerHistoryController extends Controller
{
    public function __construct(private readonly CustomerHistoryService $history) {}

    public function index(ReportRequest $request): Response
    {
        $filters = [
            'client' => $request->idFilter('client'),
            'tab' => $request->validated('tab') ?: CustomerHistoryService::TABS[0],
            'mode' => $request->validated('mode') ?: CustomerHistoryService::STATEMENT_MODES[0],
            'from' => $request->dateFilter('from'),
            'to' => $request->dateFilter('to'),
            'group' => $request->validated('group'),
            'document_search' => $request->textFilter('document_search'),
            'item_search' => $request->textFilter('item_search'),
            'movement_search' => $request->textFilter('movement_search'),
            'product' => $request->idFilter('product'),
        ];

        $client = $filters['client'] ? User::query()->with('roles')->find($filters['client']) : null;
        $filters['client'] = $client?->id;

        return Inertia::render('Accounting/Report/CustomerHistory', [
            'filters' => $filters,
            'client' => $client ? [
                'id' => (int) $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'email' => $client->email,
                'tax_number' => $client->tax_number,
                'address' => $client->address,
                'roles' => $client->roles->map(fn ($role) => __($role->name))->values(),
            ] : null,
            'clientOption' => $client ? ['value' => (int) $client->id, 'label' => $this->history->clientLabel($client)] : null,
            'documentGroups' => SelectOptions::from($this->history->documentGroups()),
            ...($client ? $this->clientData($client, $filters) : []),
        ]);
    }

    /**
     * بحث الاطراف لحقل الطرف.
     */
    public function clients(Request $request): JsonResponse
    {
        return response()->json($this->history->clientOptions($request->string('q')->toString()));
    }

    /**
     * الملخص وبيانات التبويب المفتوح.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function clientData(User $client, array $filters): array
    {
        $summary = $this->history->summary($client->id, $filters['from'], $filters['to']);

        return [
            'summary' => [
                ['label' => __('Net Sales'), 'value' => Number::money($summary['net_sales']), 'hint' => __('Sales').' '.Number::money($summary['sales']).' − '.__('Returns').' '.Number::money($summary['sales_returns']), 'tone' => 'brand'],
                ['label' => __('Receipts'), 'value' => Number::money($summary['receipts']), 'hint' => __('Cheques under collection').': '.Number::money($summary['cheques_under_collection']), 'tone' => 'success'],
                ['label' => __('Net Purchases'), 'value' => Number::money($summary['net_purchases']), 'hint' => __('Purchases').' '.Number::money($summary['purchases']).' − '.__('Returns').' '.Number::money($summary['purchase_returns']), 'tone' => 'warning'],
                ['label' => __('Payments'), 'value' => Number::money($summary['payments']), 'hint' => __('Open sales orders').': '.$summary['open_sales_orders'], 'tone' => 'danger'],
                ['label' => __('Last Sale'), 'value' => $this->date($summary['last_sale_date']) ?? '-', 'hint' => $summary['last_sale_reference'] ?? __('No sales yet'), 'tone' => 'neutral'],
            ],
            ...match ($filters['tab']) {
                'documents' => ['documents' => $this->documents($client->id, $filters)],
                'items' => ['items' => $this->items($client->id, $filters)],
                'movements' => [
                    'movements' => $this->movements($client->id, $filters),
                    'movementProduct' => $filters['product'] ? (ProductSearch::label($filters['product']) ?? '#'.$filters['product']) : null,
                ],
                default => ['statement' => $this->statement($client->id, $filters)],
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function statement(int $clientId, array $filters): array
    {
        $statement = $this->history->statement($clientId, $filters['from'], $filters['to'], $filters['mode'] === 'detailed');

        return [
            'has_account' => $statement['has_account'],
            'opening' => $this->balance($statement['opening']),
            'closing' => $this->balance($statement['closing']),
            'debit' => Number::money($statement['debit']),
            'credit' => Number::money($statement['credit']),
            'rows' => collect($statement['rows'])->map(fn (array $row) => [
                'id' => $row['id'],
                'date' => $this->date($row['date']),
                'entry_number' => $row['entry_number'],
                'source' => $row['source'],
                'reference_no' => $row['reference_no'],
                'description' => $row['description'],
                'voided' => $row['voided'],
                'debit' => $row['debit'] ? Number::money($row['debit']) : '',
                'credit' => $row['credit'] ? Number::money($row['credit']) : '',
                'balance' => $this->balance($row['balance']),
                'url' => $row['url'],
                'pdf_url' => $row['pdf_url'],
                'details' => collect($row['details'])->map(fn (array $detail) => [
                    'description' => $detail['description'],
                    'quantity' => $detail['quantity'] !== null ? Number::quantity($detail['quantity']).' × '.Number::money($detail['unit_price']) : null,
                    'total' => trim(Number::money($detail['line_total']).' '.$detail['currency']),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function documents(int $clientId, array $filters): array
    {
        return $this->history->documents($clientId, $filters['from'], $filters['to'], $filters['group'] ?: null, $filters['document_search'])
            ->map(fn (array $document) => [
                'key' => $document['key'],
                'date' => $this->date($document['date']),
                'type' => $document['type_label'],
                'direction' => $document['direction'],
                'reference_no' => $document['reference_no'],
                'status' => $document['status_label'] ? ['label' => $document['status_label'], 'tone' => StatusBadge::tone($document['status_color'])] : null,
                'amount' => $document['amount'] === null ? null : trim(Number::money($document['amount']).' '.$document['currency']),
                'base_amount' => $document['base_amount'] === null ? null : Number::money($document['base_amount']),
                'url' => $document['url'],
                'pdf_url' => $document['pdf_url'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function items(int $clientId, array $filters): array
    {
        return $this->history->itemPrices($clientId, $filters['item_search'])
            ->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'codes' => collect([$item['sku'], $item['barcode']])->filter()->unique()->implode(' · '),
                'last_sale_price' => $item['last_sale_price'] !== null ? trim(Number::money($item['last_sale_price']).' '.$item['last_sale_currency']) : null,
                'last_sale_discount' => $item['last_sale_discount'] > 0 ? Number::money($item['last_sale_discount']) : null,
                'last_sale_date' => $this->date($item['last_sale_date']),
                'last_sale_reference' => $item['last_sale_reference'],
                'price_range' => $item['min_sale_price'] !== null && $item['min_sale_price'] != $item['max_sale_price']
                    ? Number::money($item['min_sale_price']).' – '.Number::money($item['max_sale_price'])
                    : null,
                'sales_count' => $item['sales_count'] ?: null,
                'sold_quantity' => $item['sold_quantity'] ? Number::quantity($item['sold_quantity']) : null,
                'last_purchase_price' => $item['last_purchase_price'] !== null ? trim(Number::money($item['last_purchase_price']).' '.$item['last_purchase_currency']) : null,
                'last_purchase_date' => $this->date($item['last_purchase_date']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function movements(int $clientId, array $filters): array
    {
        return $this->history->itemMovements($clientId, $filters['from'], $filters['to'], $filters['product'], $filters['movement_search'])
            ->map(fn (array $movement) => [
                'key' => $movement['key'],
                'date' => $this->date($movement['date']),
                'type' => $movement['type_label'],
                'direction' => $movement['direction'],
                'posted' => $movement['posted'],
                'reference_no' => $movement['reference_no'],
                'name' => $movement['name'],
                'quantity' => Number::quantity($movement['quantity']),
                'unit_price' => trim(Number::money($movement['unit_price']).' '.$movement['currency']),
                'discount' => $movement['discount_amount'] > 0 ? Number::money($movement['discount_amount']) : null,
                'total' => Number::money($movement['line_total']),
            ])
            ->values()
            ->all();
    }

    /**
     * الرصيد المدين يعني ان الطرف مطالَب بالمبلغ، والدائن يعني انه مستحق له.
     */
    private function balance(float $value): string
    {
        return Number::money(abs($value)).' '.($value >= 0 ? __('Dr') : __('Cr'));
    }

    private function date(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : null;
    }
}
