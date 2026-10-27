<?php

namespace App\Services\Purchasing;

use App\Models\Department;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuotation;
use App\Models\StoreItem;
use App\Services\Finance\BudgetLedger;
use App\Services\Store\StockLedger;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The purchase chain (erp/docs/PURCHASING.md): quotation → order → goods
 * received → vendor invoice. Amounts are computed here, never taken from
 * the client.
 */
final class PurchaseDesk
{
    public function __construct(private readonly StockLedger $stock, private readonly BudgetLedger $budget) {}

    /**
     * Replaces the lines of a quotation or a draft order and returns the totals.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal: float, gst_amount: float, total_amount: float}
     */
    public function setLines(string $parentType, int $parentId, array $lines): array
    {
        PurchaseLine::query()->where('parent_type', $parentType)->where('parent_id', $parentId)->delete();
        $subtotal = 0.0;
        $gst = 0.0;
        foreach ($lines as $i => $line) {
            $item = isset($line['item_id']) ? StoreItem::query()->where('is_active', true)->find($line['item_id']) : null;
            if (isset($line['item_id']) && $item === null) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => [__('store.item_not_found')]]);
            }
            $base = round((float) $line['quantity'] * (float) $line['rate'], 2);
            $tax = round($base * (float) ($line['gst_percent'] ?? 0) / 100, 2);
            PurchaseLine::create([
                'parent_type' => $parentType, 'parent_id' => $parentId, 'item_id' => $item?->id,
                'description' => trim((string) ($line['description'] ?? '')) ?: (string) $item?->item_name,
                'quantity' => $line['quantity'], 'unit' => $line['unit'] ?? $item?->unit, 'rate' => $line['rate'],
                'gst_percent' => $line['gst_percent'] ?? 0, 'amount' => round($base + $tax, 2),
            ]);
            $subtotal += $base;
            $gst += $tax;
        }

        return ['subtotal' => round($subtotal, 2), 'gst_amount' => round($gst, 2), 'total_amount' => round($subtotal + $gst, 2)];
    }

    /** Accepting a quotation makes a draft order with its lines; other open quotations of the department stay as they are. */
    public function orderFromQuotation(PurchaseQuotation $quotation, string $orderDate, int $userId): PurchaseOrder
    {
        if ($quotation->status !== 'received') {
            throw ValidationException::withMessages(['quotation' => [__('purchasing.quotation_closed')]]);
        }

        return DB::transaction(function () use ($quotation, $orderDate, $userId) {
            $quotation->forceFill(['status' => 'accepted'])->save();
            $order = PurchaseOrder::create([
                'organization_id' => $quotation->organization_id, 'department_id' => $quotation->department_id, 'vendor_id' => $quotation->vendor_id,
                'quotation_id' => $quotation->id, 'order_date' => $orderDate, 'status' => 'draft', 'created_by' => $userId,
            ]);
            $lines = $quotation->lines->map(fn (PurchaseLine $l) => [
                'item_id' => $l->item_id, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'rate' => $l->rate, 'gst_percent' => $l->gst_percent,
            ])->all();
            $order->forceFill($this->setLines('purchase_order', $order->id, $lines))->save();

            return $order;
        });
    }

    /** Issuing gives the order its number (PO/26-27/001) and fixes its lines. */
    public function issue(PurchaseOrder $order, int $userId): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => [__('purchasing.not_draft')]]);
        }
        if (! $order->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => [__('purchasing.no_lines')]]);
        }

        return DB::transaction(function () use ($order, $userId) {
            [, $label] = NumberSequence::financialYear(CarbonImmutable::parse($order->order_date));
            $n = NumberSequence::next($order->organization_id, 'purchase_order', $label);
            $order->forceFill(['status' => 'issued', 'po_number' => sprintf('PO/%s/%03d', $label, $n), 'issued_by' => $userId, 'issued_at' => now()])->save();

            return $order;
        });
    }

    /**
     * Goods received against an issued order. Lines naming a store item add
     * to its stock at the order rate.
     *
     * @param  array<int, float>  $quantities  purchase line id => quantity received now
     */
    public function receive(PurchaseOrder $order, array $quantities, string $date, ?string $remarks, int $userId): GoodsReceipt
    {
        if (! in_array($order->status, ['issued', 'partly_received'], true)) {
            throw ValidationException::withMessages(['status' => [__('purchasing.not_receivable')]]);
        }
        $quantities = array_filter($quantities, fn ($q) => (float) $q > 0);
        if ($quantities === []) {
            throw ValidationException::withMessages(['lines' => [__('purchasing.nothing_received')]]);
        }
        if ($date < $order->order_date->toDateString()) {
            throw ValidationException::withMessages(['received_date' => [__('purchasing.before_order')]]);
        }

        return DB::transaction(function () use ($order, $quantities, $date, $remarks, $userId) {
            PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            $lines = $order->lines()->get()->keyBy('id');
            [, $label] = NumberSequence::financialYear(CarbonImmutable::parse($date));
            $receipt = GoodsReceipt::create([
                'organization_id' => $order->organization_id, 'purchase_order_id' => $order->id, 'received_date' => $date, 'remarks' => $remarks, 'received_by' => $userId,
                'grn_number' => sprintf('GRN/%s/%03d', $label, NumberSequence::next($order->organization_id, 'goods_receipt', $label)),
            ]);
            foreach ($quantities as $lineId => $qty) {
                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages(["lines.{$lineId}" => [__('purchasing.line_not_found')]]);
                }
                $open = round((float) $line->quantity - (float) $line->received_quantity, 2);
                if ((float) $qty > $open + 0.001) {
                    throw ValidationException::withMessages(["lines.{$lineId}" => [__('purchasing.more_than_ordered', ['open' => rtrim(rtrim(number_format($open, 2, '.', ''), '0'), '.')])]]);
                }
                $receiptLine = $receipt->lines()->create(['purchase_line_id' => $line->id, 'quantity' => round((float) $qty, 2)]);
                $line->forceFill(['received_quantity' => round((float) $line->received_quantity + (float) $qty, 2)])->save();
                if ($line->item_id !== null && ($item = StoreItem::query()->find($line->item_id)) !== null) {
                    $this->stock->move($item, 'receive', (float) $qty, [
                        'movement_date' => $date, 'unit_cost' => $line->rate, 'department_id' => $order->department_id,
                        'source_type' => 'goods_receipt_line', 'source_id' => $receiptLine->id, 'remarks' => $receipt->grn_number,
                    ], $userId);
                }
            }
            $all = $order->lines()->get();
            $done = $all->every(fn (PurchaseLine $l) => (float) $l->received_quantity + 0.001 >= (float) $l->quantity);
            $order->forceFill(['status' => $done ? 'received' : 'partly_received'])->save();

            return $receipt;
        });
    }

    /** Value of the goods received so far (with GST), which caps what can be invoiced. */
    public function receivedValue(PurchaseOrder $order): float
    {
        return round($order->lines()->get()->sum(fn (PurchaseLine $l) => (float) $l->received_quantity * (float) $l->rate * (1 + (float) $l->gst_percent / 100)), 2);
    }

    /**
     * Records a vendor invoice: goods billed may not exceed goods received
     * and not yet invoiced; the total is charged to the department budget.
     *
     * @param  array<string, mixed>  $data  invoice_number, invoice_date, goods_amount, other_charges, remarks
     */
    public function invoice(PurchaseOrder $order, array $data, int $userId): PurchaseInvoice
    {
        if (! in_array($order->status, ['partly_received', 'received'], true)) {
            throw ValidationException::withMessages(['status' => [__('purchasing.nothing_to_invoice')]]);
        }

        return DB::transaction(function () use ($order, $data, $userId) {
            PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            $open = round($this->receivedValue($order) - (float) $order->invoices()->sum('goods_amount'), 2);
            if ((float) $data['goods_amount'] > $open + 0.5) {
                throw ValidationException::withMessages(['goods_amount' => [__('purchasing.more_than_received', ['open' => number_format(max($open, 0), 2)])]]);
            }
            $other = round((float) ($data['other_charges'] ?? 0), 2);
            $invoice = PurchaseInvoice::create([
                'organization_id' => $order->organization_id, 'purchase_order_id' => $order->id, 'department_id' => $order->department_id, 'vendor_id' => $order->vendor_id,
                'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'], 'goods_amount' => round((float) $data['goods_amount'], 2),
                'other_charges' => $other, 'total_amount' => round((float) $data['goods_amount'] + $other, 2), 'remarks' => $data['remarks'] ?? null, 'created_by' => $userId,
            ]);
            $this->budget->record(Department::query()->findOrFail($order->department_id), (float) $invoice->total_amount, 'purchase', 'purchase_invoice', $invoice->id,
                "{$order->po_number} — {$invoice->invoice_number}", $invoice->invoice_date->toDateString(), $userId);

            return $invoice;
        });
    }
}
