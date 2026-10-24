<?php

namespace App\Http\Controllers\Web\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\SaveSupplierRequest;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'archived' => ['nullable', 'boolean']]);
        $entries = Supplier::when($filters['archived'] ?? false, fn ($query) => $query->onlyTrashed())
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('phone1', 'like', '%'.$q.'%')))
            ->withCount('invoices')->withSum(['invoices as balance_minor' => fn ($query) => $query->where('status', 'approved')], DB::raw('total_minor - paid_minor'))
            ->latest('id')->paginate(20)->withQueryString();

        return view('suppliers.index', compact('entries', 'filters'));
    }

    public function store(SaveSupplierRequest $request): JsonResponse|RedirectResponse
    {
        $supplier = Supplier::create($this->clean($request->validated()));
        if ($request->expectsJson()) {
            return response()->json(['message' => 'تمت إضافة المورد.', 'data' => $supplier], 201);
        }

        return redirect()->route('suppliers.index')->with('success', 'تمت إضافة المورد.');
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): JsonResponse|RedirectResponse
    {
        $supplier->update($this->clean($request->validated()));
        if ($request->expectsJson()) {
            return response()->json(['message' => 'تم تعديل المورد.', 'data' => $supplier]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('success', 'تم تعديل بيانات المورد.');
    }

    private function clean(array $data): array
    {
        return array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
    }

    public function show(Supplier $supplier): View
    {
        $entries = $supplier->invoices()->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();
        $payments = $supplier->payments()->with('invoice:id,invoice_date')->latest('payment_date')->latest('id')->paginate(20, ['*'], 'payments_page')->withQueryString();
        $totals = $supplier->invoices()->where('status', 'approved')->selectRaw('COALESCE(SUM(total_minor), 0) AS purchases, COALESCE(SUM(paid_minor), 0) AS paid')->first();
        $purchases = DecimalAmount::format((int) $totals->purchases);
        $paid = DecimalAmount::format((int) $totals->paid);
        $balance = DecimalAmount::format((int) $totals->purchases - (int) $totals->paid);
        $methods = SupplierPayment::METHODS;

        return view('suppliers.show', compact('supplier', 'entries', 'payments', 'purchases', 'paid', 'balance', 'methods'));
    }

    public function archive(Supplier $supplier): RedirectResponse
    {
        DB::transaction(function () use ($supplier): void {
            $current = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $hasBalance = PurchaseInvoice::where('supplier_id', $current->id)->where('status', 'approved')->whereColumn('total_minor', '>', 'paid_minor')->exists();
            if ($hasBalance) {
                throw ValidationException::withMessages(['supplier' => 'لا يمكن أرشفة مورد لديه مبالغ غير مسددة.']);
            }
            $current->delete();
        }, 3);

        return redirect()->route('suppliers.index')->with('success', 'تمت أرشفة المورد مع الاحتفاظ بفواتيره وسجله.');
    }

    public function restore(int $supplier): RedirectResponse
    {
        Supplier::onlyTrashed()->findOrFail($supplier)->restore();

        return redirect()->route('suppliers.index')->with('success', 'تمت استعادة المورد.');
    }
}
