<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderAdditionalCost;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderAttachment;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderClearance;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderDelivery;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderInsurance;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderNote;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderReappraisal;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderShipment;

class ConsolidatedPurchaseOrderLogisticsService
{
    public function createReappraisal(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderReappraisal
    {
        $lines = $data['lines'] ?? [];
        $items = $data['items'] ?? [];
        unset($data['lines'], $data['items']);

        $reappraisal = $order->reappraisals()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        $this->syncReappraisalLines($reappraisal, $lines);
        $this->syncSelectedOrderItems($reappraisal->items(), $items);

        return $reappraisal->refresh();
    }

    /**
     * البنود والاصناف تُعاد بناؤها فقط اذا ارسلها النموذج، حتى لا يمسحها
     * تعديل جزئي لا يحمل الا حقول اعادة التخمين نفسها.
     */
    public function updateReappraisal(ConsolidatedPurchaseOrderReappraisal $reappraisal, array $data): ConsolidatedPurchaseOrderReappraisal
    {
        $lines = $data['lines'] ?? null;
        $items = $data['items'] ?? null;
        unset($data['lines'], $data['items']);

        $reappraisal->update($data);

        if ($lines !== null) {
            $this->syncReappraisalLines($reappraisal, $lines);
        }

        if ($items !== null) {
            $this->syncSelectedOrderItems($reappraisal->items(), $items);
        }

        return $reappraisal->refresh();
    }

    public function deleteReappraisal(ConsolidatedPurchaseOrderReappraisal $reappraisal): void
    {
        // الحذف ناعم فلا يشتغل cascade القاعدة على الصفوف التابعة.
        $reappraisal->lines()->delete();
        $reappraisal->items()->delete();
        $reappraisal->delete();
    }

    /**
     * اعادة بناء بنود اعادة التخمين من صفوف النموذج.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncReappraisalLines(ConsolidatedPurchaseOrderReappraisal $reappraisal, array $lines): void
    {
        $reappraisal->lines()->delete();

        foreach ($lines as $line) {
            // صف بلا بند مختار لا معنى لقيمته.
            if (blank($line['reappraisal_element_id'] ?? null)) {
                continue;
            }

            $reappraisal->lines()->create([
                'reappraisal_element_id' => $line['reappraisal_element_id'],
                'amount' => filled($line['amount'] ?? null) ? $line['amount'] : 0,
                'notes' => filled($line['notes'] ?? null) ? $line['notes'] : null,
            ]);
        }
    }

    public function createAdditionalCost(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderAdditionalCost
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $additionalCost = $order->additionalCosts()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        $this->syncSelectedOrderItems($additionalCost->items(), $items);

        return $additionalCost->refresh();
    }

    public function updateAdditionalCost(ConsolidatedPurchaseOrderAdditionalCost $additionalCost, array $data): ConsolidatedPurchaseOrderAdditionalCost
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $additionalCost->update($data);
        $this->syncSelectedOrderItems($additionalCost->items(), $items);

        return $additionalCost->refresh();
    }

    public function deleteAdditionalCost(ConsolidatedPurchaseOrderAdditionalCost $additionalCost): void
    {
        // الحذف هنا ناعم (SoftDeletes) فلا يشتغل cascade القاعدة، والصفوف
        // المرتبطة تبقى معلّقة تشير الى تكلفة لم تعد ظاهرة.
        $additionalCost->items()->delete();
        $additionalCost->delete();
    }

    /**
     * اعادة بناء اصناف الطلبية المؤشَّرة (للتكلفة الاضافية او لاعادة التخمين)
     * من صفوف النموذج.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncSelectedOrderItems(HasMany $relation, array $items): void
    {
        $relation->delete();

        foreach ($items as $item) {
            // التأشير هو القرار: صنف غير مؤشَّر لا يُحفظ ولو كُتبت له ملاحظة،
            // والا صار للسجل اصناف لم يخترها احد.
            if (blank($item['consolidated_purchase_order_item_id'] ?? null) || ! ($item['selected'] ?? false)) {
                continue;
            }

            $relation->create([
                'consolidated_purchase_order_item_id' => $item['consolidated_purchase_order_item_id'],
                'notes' => filled($item['notes'] ?? null) ? $item['notes'] : null,
            ]);
        }
    }

    public function createShipment(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderShipment
    {
        return $order->shipments()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateShipment(ConsolidatedPurchaseOrderShipment $shipment, array $data): ConsolidatedPurchaseOrderShipment
    {
        $shipment->update($data);

        return $shipment->refresh();
    }

    public function deleteShipment(ConsolidatedPurchaseOrderShipment $shipment): void
    {
        $shipment->delete();
    }

    public function createInsurance(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderInsurance
    {
        return $order->insurances()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateInsurance(ConsolidatedPurchaseOrderInsurance $insurance, array $data): ConsolidatedPurchaseOrderInsurance
    {
        $insurance->update($data);

        return $insurance->refresh();
    }

    public function deleteInsurance(ConsolidatedPurchaseOrderInsurance $insurance): void
    {
        $insurance->delete();
    }

    public function createClearance(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderClearance
    {
        $attachments = $data['attachments'] ?? [];
        unset($data['attachments']);

        $clearance = $order->clearances()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        $this->syncClearanceAttachments($clearance, $attachments);

        return $clearance->refresh();
    }

    public function updateClearance(ConsolidatedPurchaseOrderClearance $clearance, array $data): ConsolidatedPurchaseOrderClearance
    {
        $attachments = $data['attachments'] ?? [];
        unset($data['attachments']);

        $clearance->update($data);
        $this->syncClearanceAttachments($clearance, $attachments);

        return $clearance->refresh();
    }

    public function deleteClearance(ConsolidatedPurchaseOrderClearance $clearance): void
    {
        $clearance->attachments()->delete();
        $clearance->delete();
    }

    private function syncClearanceAttachments(ConsolidatedPurchaseOrderClearance $clearance, array $attachments): void
    {
        $clearance->attachments()->delete();

        foreach ($attachments as $attachment) {
            if (blank($attachment['copy_path'] ?? null) && blank($attachment['original_path'] ?? null)) {
                continue;
            }

            $clearance->attachments()->create([
                'attachment_type_id' => $attachment['attachment_type_id'] ?? null,
                'copy_path' => $attachment['copy_path'] ?? null,
                'original_path' => $attachment['original_path'] ?? null,
            ]);
        }
    }

    public function createDelivery(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderDelivery
    {
        $costItems = $data['cost_items'] ?? [];
        unset($data['cost_items']);

        $delivery = $order->deliveries()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        $this->syncDeliveryCostItems($delivery, $costItems);

        return $delivery->refresh();
    }

    public function updateDelivery(ConsolidatedPurchaseOrderDelivery $delivery, array $data): ConsolidatedPurchaseOrderDelivery
    {
        $costItems = $data['cost_items'] ?? [];
        unset($data['cost_items']);

        $delivery->update($data);
        $this->syncDeliveryCostItems($delivery, $costItems);

        return $delivery->refresh();
    }

    public function deleteDelivery(ConsolidatedPurchaseOrderDelivery $delivery): void
    {
        $delivery->costItems()->delete();
        $delivery->delete();
    }

    private function syncDeliveryCostItems(ConsolidatedPurchaseOrderDelivery $delivery, array $costItems): void
    {
        $delivery->costItems()->delete();

        foreach ($costItems as $item) {
            if (blank($item['amount'] ?? null) && blank($item['notes'] ?? null)) {
                continue;
            }

            $delivery->costItems()->create([
                'cost_element_id' => $item['cost_element_id'] ?? null,
                'amount' => filled($item['amount'] ?? null) ? $item['amount'] : null,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    public function createAttachment(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderAttachment
    {
        return $order->attachments()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateAttachment(ConsolidatedPurchaseOrderAttachment $attachment, array $data): ConsolidatedPurchaseOrderAttachment
    {
        $attachment->update($data);

        return $attachment->refresh();
    }

    public function deleteAttachment(ConsolidatedPurchaseOrderAttachment $attachment): void
    {
        $attachment->delete();
    }

    public function createNote(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderNote
    {
        return $order->orderNotes()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateNote(ConsolidatedPurchaseOrderNote $note, array $data): ConsolidatedPurchaseOrderNote
    {
        $note->update($data);

        return $note->refresh();
    }

    public function deleteNote(ConsolidatedPurchaseOrderNote $note): void
    {
        $note->delete();
    }
}
