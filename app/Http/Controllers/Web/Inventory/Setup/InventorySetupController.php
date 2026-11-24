<?php

namespace App\Http\Controllers\Web\Inventory\Setup;

use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Setup\DeleteInventoryRequest;
use App\Http\Requests\Inventory\Setup\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\OpeningStockItem;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventorySetupController extends Controller
{
    public function readiness(Request $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        return response()->json($correct->deletionReadiness($inventory, $request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function route(Request $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        $direct = $correct->deletionReadiness($inventory, $request->user());
        if ($direct['allowed']) {
            return response()->json($direct + ['mode' => 'opening_direct'])->header('Cache-Control', 'no-store, private');
        }
        $movement = StockMovement::where('branch_id', $inventory->branch_id)->where('product_variant_id', $inventory->product_variant_id)->orderBy('id')->first();
        $kind = match ($movement?->source_type) {
            OpeningStockItem::class => 'opening',
            PurchaseInvoiceItem::class => 'purchase',
            default => null,
        };
        if (! $kind || ! $movement?->source_id) {
            return response()->json($direct + ['mode' => 'blocked'])->header('Cache-Control', 'no-store, private');
        }
        $item = $movement->source_type::find($movement->source_id);
        if (! $item || ! $item->opening_stock_entry_id && ! $item->purchase_invoice_id) {
            return response()->json($direct + ['mode' => 'blocked'])->header('Cache-Control', 'no-store, private');
        }
        $documentId = $kind === 'opening' ? $item->opening_stock_entry_id : $item->purchase_invoice_id;

        return response()->json(['allowed' => true, 'mode' => 'document_correction', 'reason' => 'سيُحفظ التصحيح كسجل مستقل حفاظًا على تاريخ المستند.',
            'url' => route('inventory.document-corrections.show', [$kind, $documentId, $item->id])])->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        $correct->update($inventory, $request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => 'تم تعديل بيانات المخزون وتحديث الرصيد الافتتاحي المرتبط.']);
    }

    public function destroy(DeleteInventoryRequest $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        $correct->delete($inventory, $request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => 'تم حذف سطر المخزون من الفرع وتحديث الرصيد الافتتاحي المرتبط.']);
    }
}
