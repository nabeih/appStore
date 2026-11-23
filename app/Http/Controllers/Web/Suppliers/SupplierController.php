<?php

namespace App\Http\Controllers\Web\Suppliers;

use App\Actions\Finance\ManageSupplierDebtSettlement;
use App\Actions\Search\ModuleSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Requests\Suppliers\SaveSupplierRequest;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\SupplierPayment;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Sales\Money;
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
            ->where(fn ($query) => app(ModuleSearch::class)->contacts($query, $filters['q'] ?? '', true))
            ->withCount(['invoices' => fn ($query) => app(ModuleSearch::class)->branches($query, $request->user())])->withSum(['invoices as balance_minor' => fn ($query) => app(ModuleSearch::class)->branches($query, $request->user())->where('status', 'approved')], DB::raw('total_minor - paid_minor'))
            ->latest('id')->paginate(20)->withQueryString();
        $deltas = PurchaseInvoice::correctionTotalsBySupplier(app(ModuleSearch::class)->branches(PurchaseInvoice::whereIn('supplier_id', $entries->modelKeys())->where('status', 'approved'), $request->user()));
        $debtBalances = app(ManageSupplierDebtSettlement::class)->installed()
            ? app(ModuleSearch::class)->branches(SupplierDebtSettlement::whereIn('supplier_id', $entries->modelKeys())->where('status', 'approved'), $request->user())
                ->selectRaw('supplier_id, SUM(amount_minor - paid_minor) AS balance')->groupBy('supplier_id')->pluck('balance', 'supplier_id')->all() : [];
        foreach ($entries as $supplier) {
            $supplier->balance_minor = Money::sum([(int) $supplier->balance_minor, $deltas[$supplier->id] ?? 0, (int) ($debtBalances[$supplier->id] ?? 0)]);
        }

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

    public function show(Request $request, Supplier $supplier): View
    {
        $filters = $request->validate(SearchRequest::filterRules() + [
            'invoices_q' => ['nullable', 'string', 'max:100'], 'payments_q' => ['nullable', 'string', 'max:100'],
            'payments_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'debts_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $search = app(ModuleSearch::class);
        $debtsAvailable = app(ManageSupplierDebtSettlement::class)->installed();
        $entries = $search->documents($search->branches($supplier->invoices(), $request->user()),
            array_replace($filters, ['q' => $filters['invoices_q'] ?? '']), 'PI', ['supplier_name', 'supplier_invoice_number', 'notes'])
            ->with('corrections')->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();
        $payments = $search->receipts($supplier->payments(), array_replace($filters, ['q' => $filters['payments_q'] ?? '']),
            $request->user(), true)->with($debtsAvailable ? ['invoice:id,invoice_date', 'settlement:id'] : ['invoice:id,invoice_date'])->latest('payment_date')->latest('id')->paginate(20, ['*'], 'payments_page')->withQueryString();
        $totals = $search->branches($supplier->invoices(), $request->user())->where('status', 'approved')
            ->selectRaw('COALESCE(SUM(total_minor),0) AS purchases, COALESCE(SUM(paid_minor),0) AS paid')->first();
        $deltas = PurchaseInvoice::correctionTotalsBySupplier($search->branches(PurchaseInvoice::where('supplier_id', $supplier->id)->where('status', 'approved'), $request->user()));
        $totals->purchases = Money::add((int) $totals->purchases, $deltas[$supplier->id] ?? 0);
        $purchases = DecimalAmount::format((int) $totals->purchases);
        $settlements = $debtsAvailable ? $search->branches($supplier->debtSettlements(), $request->user())->latest('id')->paginate(20, ['*'], 'debts_page')->withQueryString() : null;
        $debtTotals = $debtsAvailable ? $search->branches($supplier->debtSettlements(), $request->user())->where('status', 'approved')->selectRaw('COALESCE(SUM(amount_minor),0) AS total, COALESCE(SUM(paid_minor),0) AS paid')->first() : null;
        $opening = DecimalAmount::format((int) ($debtTotals?->total ?? 0));
        $paidMinor = Money::add((int) $totals->paid, (int) ($debtTotals?->paid ?? 0));
        $paid = DecimalAmount::format($paidMinor);
        $balance = DecimalAmount::format(Money::sum([(int) $totals->purchases, (int) ($debtTotals?->total ?? 0), -$paidMinor]));
        $methods = SupplierPayment::METHODS;

        return view('suppliers.show', compact('supplier', 'entries', 'payments', 'purchases', 'paid', 'balance', 'methods', 'filters', 'debtsAvailable', 'settlements', 'opening'));
    }

    public function archive(Supplier $supplier): RedirectResponse
    {
        DB::transaction(function () use ($supplier): void {
            $current = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $hasBalance = PurchaseInvoice::where('supplier_id', $current->id)->where('status', 'approved')->with('corrections')->get()
                ->contains(fn ($invoice): bool => $invoice->effective_total_minor > (int) $invoice->paid_minor);
            $hasDebtBalance = app(ManageSupplierDebtSettlement::class)->installed() && $current->debtSettlements()->where('status', 'approved')->whereColumn('amount_minor', '>', 'paid_minor')->exists();
            if ($hasBalance || $hasDebtBalance) {
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
