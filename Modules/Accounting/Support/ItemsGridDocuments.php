<?php

namespace Modules\Accounting\Support;

use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\PriceResolver;

/**
 * المستندات التي تستعمل جدول الاصناف في لوحة Vue، وما يختلف بينها على السيرفر: جهة التسعير
 * (بيع او شراء)، دور الطرف، اقتراح الملحقات، والصلاحيات التي تفتح نقاط الجدول
 * (admin/accounting/items-grid/{document}/*). الجهة تُقرأ من هنا لا من الطلب، فلا يطلب
 * مستند بيع اسعار الشراء.
 *
 * مستند جديد يُنقل الى Vue يُضاف هنا بسطر واحد.
 */
final class ItemsGridDocuments
{
    /**
     * @var array<string, array{side: string, party: string, accessories: bool, permissions: list<string>}>
     */
    private const DOCUMENTS = [
        'sales-quotation' => ['side' => PriceResolver::SELL, 'party' => 'customer', 'accessories' => true, 'permissions' => ['Sales Quotation Create', 'Sales Quotation Update']],
        'sales-order' => ['side' => PriceResolver::SELL, 'party' => 'customer', 'accessories' => true, 'permissions' => ['Sales Order Create', 'Sales Order Update']],
        'sales-invoice' => ['side' => PriceResolver::SELL, 'party' => 'customer', 'accessories' => true, 'permissions' => ['Sales Invoice Create', 'Sales Invoice Update']],
        'sales-return' => ['side' => PriceResolver::SELL, 'party' => 'customer', 'accessories' => false, 'permissions' => ['Sales Return Create', 'Sales Return Update']],
        'purchase-quotation' => ['side' => PriceResolver::BUY, 'party' => 'supplier', 'accessories' => false, 'permissions' => ['Purchase Quotation Create', 'Purchase Quotation Update']],
        'purchase-order' => ['side' => PriceResolver::BUY, 'party' => 'supplier', 'accessories' => false, 'permissions' => ['Purchase Order Create', 'Purchase Order Update']],
        'purchase-invoice' => ['side' => PriceResolver::BUY, 'party' => 'supplier', 'accessories' => false, 'permissions' => ['Purchase Invoice Create', 'Purchase Invoice Update']],
        'purchase-return' => ['side' => PriceResolver::BUY, 'party' => 'supplier', 'accessories' => false, 'permissions' => ['Purchase Return Create', 'Purchase Return Update']],
        'production-order' => ['side' => PriceResolver::BUY, 'party' => 'any', 'accessories' => false, 'permissions' => ['Production Order Create', 'Production Order Update']],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    public static function exists(?string $document): bool
    {
        return $document !== null && isset(self::DOCUMENTS[$document]);
    }

    /**
     * @return list<string>
     */
    public static function permissions(string $document): array
    {
        return self::DOCUMENTS[$document]['permissions'] ?? [];
    }

    /**
     * دور الطرف في حقل اختياره (PartySelect::lookup): customer او supplier.
     */
    public static function party(string $document): string
    {
        return self::DOCUMENTS[$document]['party'] ?? 'any';
    }

    public static function side(string $document): string
    {
        return self::DOCUMENTS[$document]['side'] ?? PriceResolver::SELL;
    }

    /**
     * منطق الجدول لهذا المستند، مسعّراً لطرفه وسعر صرفه.
     */
    public static function grid(string $document, LinePriceContext $context): ItemsGrid
    {
        return ItemsGrid::for(self::side($document), $context, self::DOCUMENTS[$document]['accessories'] ?? false);
    }
}
