<?php

namespace App\Http\Controllers\Web\Purchases;

use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\StoreSupplierPaymentRequest;
use App\Models\PurchaseInvoice;
use Illuminate\Http\JsonResponse;

class SupplierPaymentController extends Controller
{
    public function store(StoreSupplierPaymentRequest $request, PurchaseInvoice $invoice, RecordSupplierPaymentAction $record): JsonResponse
    {
        $record->execute($invoice, $request->validated(), $request->user()->id);

        return response()->json(['message' => 'تم تسجيل الدفعة وتحديث المتبقي.', 'url' => route('purchases.invoices.show', $invoice)]);
    }
}
