<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Http\Requests\Admin\ConsolidatedPurchaseOrderTabRequest;
use Modules\Accounting\Repositories\Contracts\ConsolidatedPurchaseOrderRepositoryInterface;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderFinancialFileService;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderLogisticsService;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderPriceOfferService;
use Modules\Accounting\Support\ConsolidatedPurchaseOrderTabs;

/**
 * سجلات تبويبات الطلبية التجميعية: اضافة وتعديل وحذف لكل تبويب ({tab})، والترسية. الملفات
 * تُحفظ على قرص accounting بنفس مجلدات الصفحة القديمة، والكتابة كلها في خدمات الطلبية.
 */
class ConsolidatedPurchaseOrderTabController extends Controller
{
    /**
     * علاقة كل تبويب على الطلبية، ومجلد ملفاته، ورسائله.
     *
     * @var array<string, array{relation: string, directory: string, created: string, updated: string, deleted: string}>
     */
    private const TABS = [
        'shipments' => ['relation' => 'shipments', 'directory' => 'consolidated-purchase-order-shipments', 'created' => 'Shipment added successfully', 'updated' => 'Shipment updated successfully', 'deleted' => 'Shipment deleted successfully'],
        'insurances' => ['relation' => 'insurances', 'directory' => 'consolidated-purchase-order-insurances', 'created' => 'Insurance added successfully', 'updated' => 'Insurance updated successfully', 'deleted' => 'Insurance deleted successfully'],
        'clearances' => ['relation' => 'clearances', 'directory' => 'consolidated-purchase-order-clearances', 'created' => 'Clearance added successfully', 'updated' => 'Clearance updated successfully', 'deleted' => 'Clearance deleted successfully'],
        'deliveries' => ['relation' => 'deliveries', 'directory' => '', 'created' => 'Delivery added successfully', 'updated' => 'Delivery updated successfully', 'deleted' => 'Delivery deleted successfully'],
        'attachments' => ['relation' => 'attachments', 'directory' => 'consolidated-purchase-order-attachments', 'created' => 'Attachment added successfully', 'updated' => 'Attachment updated successfully', 'deleted' => 'Attachment deleted successfully'],
        'notes' => ['relation' => 'orderNotes', 'directory' => '', 'created' => 'Note added successfully', 'updated' => 'Note updated successfully', 'deleted' => 'Note deleted successfully'],
        'additional-costs' => ['relation' => 'additionalCosts', 'directory' => '', 'created' => 'Additional cost added successfully', 'updated' => 'Additional cost updated successfully', 'deleted' => 'Additional cost deleted successfully'],
        'reappraisals' => ['relation' => 'reappraisals', 'directory' => 'consolidated-purchase-order-reappraisals', 'created' => 'Reappraisal added successfully', 'updated' => 'Reappraisal updated successfully', 'deleted' => 'Reappraisal deleted successfully'],
        'price-offers' => ['relation' => 'priceOffers', 'directory' => '', 'created' => 'Price offer added successfully', 'updated' => 'Price offer updated successfully', 'deleted' => 'Price offer deleted successfully'],
        'payments' => ['relation' => 'payments', 'directory' => 'consolidated-purchase-order-payments', 'created' => 'Payment added successfully', 'updated' => 'Payment updated successfully', 'deleted' => 'Payment deleted successfully'],
        'bank-credits' => ['relation' => 'bankCredits', 'directory' => 'consolidated-purchase-order-bank-credits', 'created' => 'Bank credit added successfully', 'updated' => 'Bank credit updated successfully', 'deleted' => 'Bank credit deleted successfully'],
    ];

    public function __construct(
        private readonly ConsolidatedPurchaseOrderRepositoryInterface $orders,
        private readonly ConsolidatedPurchaseOrderLogisticsService $logistics,
        private readonly ConsolidatedPurchaseOrderFinancialFileService $finance,
        private readonly ConsolidatedPurchaseOrderPriceOfferService $offers,
    ) {}

    public function store(ConsolidatedPurchaseOrderTabRequest $request, int $consolidatedPurchaseOrder, string $tab): RedirectResponse
    {
        $order = $this->order($consolidatedPurchaseOrder);
        $data = $this->data($request, $tab, null);

        try {
            match ($tab) {
                'shipments' => $this->logistics->createShipment($order, $data),
                'insurances' => $this->logistics->createInsurance($order, $data),
                'clearances' => $this->logistics->createClearance($order, $data),
                'deliveries' => $this->logistics->createDelivery($order, $data),
                'attachments' => $this->logistics->createAttachment($order, $data),
                'notes' => $this->logistics->createNote($order, $data),
                'additional-costs' => $this->logistics->createAdditionalCost($order, $data),
                'reappraisals' => $this->logistics->createReappraisal($order, $data),
                'price-offers' => $this->offers->create($order, $data),
                'payments' => $this->finance->createPayment($order, $data),
                'bank-credits' => $this->finance->createBankCredit($order, $data),
            };
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __(self::TABS[$tab]['created']));
    }

    public function update(ConsolidatedPurchaseOrderTabRequest $request, int $consolidatedPurchaseOrder, string $tab, int $record): RedirectResponse
    {
        $model = $this->record($consolidatedPurchaseOrder, $tab, $record);
        $data = $this->data($request, $tab, $model);

        try {
            match ($tab) {
                'shipments' => $this->logistics->updateShipment($model, $data),
                'insurances' => $this->logistics->updateInsurance($model, $data),
                'clearances' => $this->logistics->updateClearance($model, $data),
                'deliveries' => $this->logistics->updateDelivery($model, $data),
                'attachments' => $this->logistics->updateAttachment($model, $data),
                'notes' => $this->logistics->updateNote($model, $data),
                'additional-costs' => $this->logistics->updateAdditionalCost($model, $data),
                'reappraisals' => $this->logistics->updateReappraisal($model, $data),
                'price-offers' => $this->offers->update($model, $data),
                'payments' => $this->finance->updatePayment($model, $data),
                'bank-credits' => $this->finance->updateBankCredit($model, $data),
            };
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __(self::TABS[$tab]['updated']));
    }

    public function destroy(int $consolidatedPurchaseOrder, string $tab, int $record): RedirectResponse
    {
        $model = $this->record($consolidatedPurchaseOrder, $tab, $record);

        try {
            match ($tab) {
                'shipments' => $this->logistics->deleteShipment($model),
                'insurances' => $this->logistics->deleteInsurance($model),
                'clearances' => $this->logistics->deleteClearance($model),
                'deliveries' => $this->logistics->deleteDelivery($model),
                'attachments' => $this->logistics->deleteAttachment($model),
                'notes' => $this->logistics->deleteNote($model),
                'additional-costs' => $this->logistics->deleteAdditionalCost($model),
                'reappraisals' => $this->logistics->deleteReappraisal($model),
                'price-offers' => $this->offers->delete($model),
                'payments' => $this->finance->deletePayment($model),
                'bank-credits' => $this->finance->deleteBankCredit($model),
            };
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __(self::TABS[$tab]['deleted']));
    }

    /**
     * ترسية الطلبية على عرض سعر (ترسية واحدة: تحل محل السابقة).
     */
    public function award(Request $request, int $consolidatedPurchaseOrder, int $offer): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string']]);

        try {
            $this->offers->award($this->order($consolidatedPurchaseOrder), $offer, $data['notes'] ?? null);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('The purchase order was awarded successfully.'));
    }

    public function cancelAward(int $consolidatedPurchaseOrder): RedirectResponse
    {
        $this->offers->cancelAward($this->order($consolidatedPurchaseOrder));

        return back()->with('success', __('The award was cancelled.'));
    }

    private function order(int $id): ConsolidatedPurchaseOrder
    {
        /** @var ConsolidatedPurchaseOrder */
        return $this->orders->findOrFail($id);
    }

    /**
     * سجل التبويب داخل هذه الطلبية فقط.
     */
    private function record(int $orderId, string $tab, int $id): Model
    {
        /** @var HasMany $relation */
        $relation = $this->order($orderId)->{self::TABS[$tab]['relation']}();

        return $relation->findOrFail($id);
    }

    /**
     * بيانات الخدمة من الطلب: الحقول المسموحة، والملفات المرفوعة مسارات على القرص (الملف
     * الحالي يبقى ما لم يُرفع غيره او يُطلب حذفه)، وصفوف التخليص لكل نوع مرفقات.
     *
     * @return array<string, mixed>
     */
    private function data(ConsolidatedPurchaseOrderTabRequest $request, string $tab, ?Model $record): array
    {
        $data = Arr::except($request->validated(), ['attachment', 'remove_attachment', 'file', 'copies', 'originals', 'remove_copies', 'remove_originals']);
        $directory = self::TABS[$tab]['directory'];

        if (in_array($tab, ['shipments', 'insurances', 'reappraisals', 'payments', 'bank-credits'], true)) {
            $data['attachment_path'] = $this->path($request->file('attachment'), $directory, $record?->getAttribute('attachment_path'), $request->boolean('remove_attachment'));
        }

        if ($tab === 'attachments') {
            $data['file_path'] = $this->path($request->file('file'), $directory, $record?->getAttribute('file_path'), false);
        }

        if ($tab === 'clearances') {
            $data['attachments'] = $this->clearanceFiles($request, $directory, $record);
        }

        if ($tab === 'deliveries') {
            $data['cost_items'] = array_values((array) ($data['cost_items'] ?? []));
        }

        return $data;
    }

    /**
     * نسخة واصل لكل نوع من مرفقات التخليص.
     *
     * @return list<array{attachment_type_id: int, copy_path: string|null, original_path: string|null}>
     */
    private function clearanceFiles(ConsolidatedPurchaseOrderTabRequest $request, string $directory, ?Model $record): array
    {
        $existing = $record ? $record->getRelationValue('attachments')->keyBy('attachment_type_id') : collect();
        $removeCopies = array_map('intval', (array) $request->input('remove_copies', []));
        $removeOriginals = array_map('intval', (array) $request->input('remove_originals', []));

        return ClearanceAttachmentType::query()->orderBy('name')->pluck('id')
            ->map(function ($typeId) use ($request, $directory, $existing, $removeCopies, $removeOriginals): array {
                $typeId = (int) $typeId;
                $current = $existing->get($typeId);

                return [
                    'attachment_type_id' => $typeId,
                    'copy_path' => $this->path($request->file("copies.{$typeId}"), $directory, $current?->copy_path, in_array($typeId, $removeCopies, true)),
                    'original_path' => $this->path($request->file("originals.{$typeId}"), $directory, $current?->original_path, in_array($typeId, $removeOriginals, true)),
                ];
            })
            ->all();
    }

    private function path(?UploadedFile $file, string $directory, ?string $current, bool $remove): ?string
    {
        if ($file) {
            return $file->store($directory, ConsolidatedPurchaseOrderTabs::DISK) ?: $current;
        }

        return $remove ? null : $current;
    }
}
