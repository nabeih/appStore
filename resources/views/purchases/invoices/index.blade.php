@extends('layouts.app')
@section('title', 'فواتير الشراء')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/purchases/invoices.css') }}">@endpush
@section('content')
<div class="screen active trade-page" data-search-region="purchases">
    <header class="trade-heading"><div><h1 class="section-title"><i class="fas fa-file-invoice text-primary" aria-hidden="true"></i> فواتير الشراء</h1><p class="section-subtitle">المشتريات ودفعات الموردين.</p></div>@can('purchases.manage')<a class="btn btn-primary" href="{{ route('purchases.invoices.create') }}">+ فاتورة جديدة</a>@endcan</header>
    <form method="get" data-module-search class="card document-search-card">
        <div class="document-search-bar"><label>البحث<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="المورد أو رقم الفاتورة أو المنتج أو SKU"></label><button class="btn btn-primary" type="submit">بحث</button><button type="button" class="btn btn-outline" data-search-clear>مسح البحث</button></div>
        <details class="document-filters" @if(array_filter(\Illuminate\Support\Arr::except($filters,'q'))) open @endif>
            <summary><span><i class="fas fa-sliders-h" aria-hidden="true"></i> خيارات التصفية</span><small>الحالة، الفرع، التاريخ</small></summary>
            <div class="document-filter-grid">
                <label>الحالة<select name="status"><option value="">كل الحالات</option>@foreach(['draft'=>'مسودة','approved'=>'معتمدة','cancelled'=>'مسودة ملغاة'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>
                <label>الفرع<select name="branch_id"><option value="">كل الفروع</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? '')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                @if(isset($filters['supplier_id']))<input type="hidden" name="supplier_id" value="{{ $filters['supplier_id'] }}">@endif
                <label>من تاريخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>إلى تاريخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
                <div class="document-filter-actions"><button class="btn btn-primary" type="submit">تطبيق</button><a class="btn btn-outline" href="{{ route('purchases.invoices.index') }}">مسح</a></div>
            </div>
        </details>
    </form>
    <section class="card document-record-list"><div class="table-responsive"><table class="mobile-records" role="table" aria-label="فواتير الشراء"><thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>المورد / الفرع</th><th>الحالة</th><th>القطع</th><th>الإجمالي ₪</th><th>المدفوع ₪</th><th>المتبقي ₪</th><th></th></tr></thead><tbody>
    @forelse($entries as $entry)
        <tr>
            <td class="record-primary" data-label="الفاتورة"><a href="{{ route('purchases.invoices.show',$entry) }}">{{ $entry->number }}</a><small>{{ $entry->supplier_invoice_number }}</small></td>
            <td data-label="التاريخ">{{ $entry->invoice_date->format('Y/m/d') }}</td>
            <td class="record-wide" data-label="المورد / الفرع">{{ $entry->supplier_name }}<small>{{ $entry->branch_name }}</small></td>
            <td class="record-status"><span class="trade-status {{ $entry->status }}">{{ ['draft'=>'مسودة','approved'=>'معتمدة','cancelled'=>'ملغاة'][$entry->status] }}</span></td>
            <td data-label="القطع">{{ $entry->total_quantity }}</td>
            <td class="record-amount" data-label="الإجمالي ₪">{{ $entry->total_amount }}</td>
            <td data-label="المدفوع ₪">{{ $entry->status==='approved' ? $entry->paid_amount : '—' }}</td>
            <td data-label="المتبقي ₪">{{ $entry->status==='approved' ? $entry->remaining_amount : '—' }}</td>
            <td class="record-action"><a class="btn btn-outline btn-sm" href="{{ route('purchases.invoices.show',$entry) }}">عرض الفاتورة</a></td>
        </tr>
    @empty
        <tr class="record-empty"><td colspan="9" class="trade-empty"><strong>لا توجد فواتير مطابقة</strong><p>ابدأ بإضافة فاتورة شراء.</p>@can('purchases.manage')<a class="btn btn-primary" href="{{ route('purchases.invoices.create') }}">فاتورة جديدة</a>@endcan</td></tr>
    @endforelse
    </tbody></table></div><x-pagination :paginator="$entries" /></section>
</div>
@endsection
