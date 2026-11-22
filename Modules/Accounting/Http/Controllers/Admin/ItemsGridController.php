<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Support\CustomerProfitMargin;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\ItemsGridDocuments;
use Modules\Accounting\Support\ItemsGridTotals;
use Modules\Accounting\Support\PartySelect;
use Modules\Accounting\Support\QuickCreate;
use Modules\Accounting\Support\SalesServiceLine;
use Modules\Accounting\Support\SelectOptions;
use Modules\Items\Enums\ItemType;
use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\PriceResolver;
use Modules\Items\Support\ProductSearch;

/**
 * نقاط JSON لجدول الاصناف في لوحة Vue (Pages/parts/items-grid). كل القواعد في Support\ItemsGrid
 * وWeightPieces وDocumentLineTotals؛ هنا قراءة الطلب فقط.
 *
 * {document} مفتاح من ItemsGridDocuments: يحدد جهة التسعير ودور الطرف والصلاحيات
 * (الوسيط AuthorizeItemsGrid). الطرف وسعر الصرف يأتيان مع الطلب لتسعير السطر.
 */
class ItemsGridController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'category_id' => ['nullable', 'integer']]);

        return response()->json(ItemsGrid::searchProducts((string) ($data['q'] ?? ''), $data['category_id'] ?? null));
    }

    public function categories(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json(ItemsGrid::searchCategories((string) ($data['q'] ?? '')));
    }

    public function pieceOutputs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source_product_id' => ['nullable', 'integer'],
            'main_product_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
        ]);

        return response()->json(ItemsGrid::searchPieceOutputs($data['source_product_id'] ?? null, (string) ($data['q'] ?? ''), $data['main_product_id'] ?? null, $data['group_id'] ?? null));
    }

    /**
     * سطر مجموعة السعر المختارة (التصنيف × اللون) بسعر الكيلو للطرف، او null.
     */
    public function group(Request $request, string $document): JsonResponse
    {
        $data = $request->validate(['group_id' => ['required', 'integer']]);

        return $this->nullable($this->grid($request, $document)->groupPayload($data['group_id']));
    }

    /**
     * سعر الكيلو لسطور المجموعات للطرف الحالي، ليُسأل المستخدم قبل تغييرها عند تغيير الطرف.
     */
    public function groupPrices(Request $request, string $document): JsonResponse
    {
        $data = $request->validate(['ids' => ['array', 'max:500'], 'ids.*' => ['integer']]);

        return response()->json((object) $this->grid($request, $document)->groupPrices($data['ids'] ?? []));
    }

    /**
     * تفاصيل الصنف المختار بسعره للطرف (وملحقاته في مستندات البيع)، او null.
     */
    public function item(Request $request, string $document): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'source_product_id' => ['nullable', 'integer'],
        ]);

        return $this->nullable($this->grid($request, $document)->itemPayload($data['product_id'], $data['source_product_id'] ?? null));
    }

    public function barcode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);

        return response()->json(ItemsGrid::resolveBarcode($data['code']));
    }

    public function matchPieces(Request $request, string $document): JsonResponse
    {
        $data = $request->validate([
            'main_product_id' => ['required', 'integer'],
            'piece_ids' => ['array'],
            'piece_ids.*' => ['integer'],
        ]);

        return response()->json($this->grid($request, $document)->matchPiecesByName($data['main_product_id'], $data['piece_ids'] ?? []));
    }

    /**
     * رصيد السطر الذي عليه المؤشر؛ null لمن لا يرى المخزون.
     */
    public function stock(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'warehouse_id' => ['nullable', 'integer']]);

        return $this->nullable(ItemsGrid::itemStock($data['product_id'], $data['warehouse_id'] ?? null));
    }

    /**
     * اسعار بنود الجدول للطرف الحالي، ليُسأل المستخدم قبل تغييرها عند تغيير الطرف.
     */
    public function prices(Request $request, string $document): JsonResponse
    {
        $data = $request->validate(['ids' => ['array', 'max:1000'], 'ids.*' => ['integer']]);

        return response()->json((object) $this->grid($request, $document)->linePrices($data['ids'] ?? []));
    }

    /**
     * مبالغ الجدول كما ستحفظها الخدمة: اوزان القطع والسطور، الكمية المسعّرة، الخصم، المجاميع
     * وتنبيهات السطور. للعرض فقط؛ الحفظ يعيد الحساب.
     */
    public function totals(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['array', 'max:2000'],
            'items.*' => ['array'],
            'extra_lines' => ['array', 'max:500'],
            'extra_lines.*' => ['array'],
            'document_discount' => ['nullable', 'numeric'],
            'tax_rate' => ['nullable', 'numeric'],
        ]);

        return response()->json(ItemsGridTotals::compute(
            $data['items'] ?? [],
            (float) ($data['document_discount'] ?? 0),
            (float) ($data['tax_rate'] ?? 0),
            $data['extra_lines'] ?? [],
        ));
    }

    /**
     * بحث الخدمات لقسم الخدمات (طلبية البيع وفاتورتها) بشكل RemoteSelect: بلا نص تُعرض اوائلها.
     */
    public function services(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json(SelectOptions::from(ProductSearch::serviceOptions((string) ($data['q'] ?? ''), 25)));
    }

    /**
     * بحث السلع بشكل RemoteSelect (قسم مواد العميل): بلا الخدمات.
     */
    public function goods(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json(SelectOptions::from(ProductSearch::options((string) ($data['q'] ?? ''), 25, itemType: ItemType::GOODS->value)));
    }

    /**
     * ما يأخذه بند الخدمة المختارة: سعرها للطرف (ترتيب تسعير السطر)، وتكلفتها ومصدرها واساس
     * تسعيرها من بطاقتها. null لصنف ليس خدمة.
     */
    public function service(Request $request, string $document): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $price = $this->grid($request, $document)->linePrices([$data['product_id']])[(int) $data['product_id']] ?? null;

        return $this->nullable(SalesServiceLine::defaults($data['product_id'], $price));
    }

    /**
     * الجهات المنفذة للخدمة بتكلفة خارجية (RemoteSelect).
     */
    public function serviceProviders(Request $request): JsonResponse
    {
        return response()->json(SalesServiceLine::providers($request->string('q')->toString()));
    }

    /**
     * جهة منفذة جديدة من داخل قسم الخدمات: مورد بحسابه في شجرة الحسابات (QuickCreate).
     */
    public function storeServiceProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => __('Name'), 'email' => __('Email'), 'phone' => __('Phone')]);

        $id = QuickCreate::party($data, 'Supplier');

        return response()->json(['value' => $id, 'label' => $data['name']]);
    }

    /**
     * حقل طرف المستند (RemoteSelect): زبائن لمستندات البيع وموردون للشراء.
     */
    public function parties(Request $request, string $document): JsonResponse
    {
        return response()->json(PartySelect::lookup(ItemsGridDocuments::party($document), $request->string('q')->toString()));
    }

    /**
     * ما يُعرض تحت حقل الطرف: نسبة ربح الزبون التي تُضاف على التكلفة في مستندات البيع.
     */
    public function party(Request $request, string $document): JsonResponse
    {
        $data = $request->validate(['party_id' => ['nullable', 'integer']]);

        return response()->json([
            'hint' => ItemsGridDocuments::side($document) === PriceResolver::SELL ? CustomerProfitMargin::hint($data['party_id'] ?? null) : null,
        ]);
    }

    /**
     * سعر صرف عملة المستند في تاريخه؛ الحفظ يحسبه من جديد.
     */
    public function exchangeRate(Request $request, CurrencyExchangeRateService $rates): JsonResponse
    {
        $data = $request->validate(['currency_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date']]);
        $rate = $rates->rateFor(isset($data['currency_id']) ? (int) $data['currency_id'] : null, $data['date'] ?? now()->toDateString());

        return response()->json(['rate' => $rate ? number_format($rate, 8, '.', '') : null]);
    }

    /**
     * JSON يقبل null (response()->json(null) يرسل {}): الصنف غير الموجود او رصيد لا يُعرض.
     */
    private function nullable(?array $data): JsonResponse
    {
        return JsonResponse::fromJsonString(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function grid(Request $request, string $document): ItemsGrid
    {
        $context = $request->validate(['party_id' => ['nullable', 'integer'], 'exchange_rate' => ['nullable', 'numeric']]);

        return ItemsGridDocuments::grid($document, LinePriceContext::for($context['party_id'] ?? null, $context['exchange_rate'] ?? null));
    }
}
