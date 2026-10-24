@extends('layouts.app')
@section('title', 'البضاعة الافتتاحية')
@section('content')
<div class="screen active os-page">
    <header class="os-page-title"><div><h1 class="section-title">البضاعة الافتتاحية</h1><p class="section-subtitle">بضاعتك الموجودة، بدون فاتورة شراء.</p></div><button type="button" class="btn btn-primary" onclick="openQuickOpeningStockModal()">+ إدخال بضاعة</button></header>
    <details class="card document-filters" @if(array_filter($filters)) open @endif>
        <summary><span><i class="fas fa-sliders-h" aria-hidden="true"></i> تصفية المستندات</span><small>الفرع والتاريخ</small></summary>
        <form method="get" class="os-filter-form document-filter-grid">
            <label class="filter-wide">الفرع<select name="branch_id"><option value="">كل الفروع</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? '') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
            <label>من تاريخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>إلى تاريخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            <div class="document-filter-actions"><button class="btn btn-primary" type="submit">تطبيق</button><a class="btn btn-outline" href="{{ route('inventory.opening-stock.index') }}">مسح</a></div>
            @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        </form>
    </details>
    <section class="card document-record-list"><div class="table-responsive"><table class="mobile-records" role="table" aria-label="مستندات البضاعة الافتتاحية"><thead><tr><th>رقم المستند</th><th>تاريخ الرصيد</th><th>الفرع</th><th>الأصناف</th><th>القطع</th><th>قيمة البضاعة ₪</th><th>الحالة</th><th></th></tr></thead><tbody>
        @forelse($entries as $entry)
            <tr>
                <td class="record-primary" data-label="المستند"><a href="{{ route('inventory.opening-stock.show', $entry) }}">{{ $entry->number }}</a></td>
                <td data-label="تاريخ الرصيد">{{ $entry->opening_date?->format('Y-m-d') ?? '—' }}</td>
                <td data-label="الفرع">{{ $entry->branch->name }}</td>
                <td data-label="الأصناف">{{ $entry->items_count }}</td>
                <td data-label="القطع">{{ $entry->total_quantity }}</td>
                <td class="record-amount" data-label="قيمة البضاعة ₪">{{ $entry->total_cost }}</td>
                <td class="record-status"><span class="badge {{ $entry->status === 'cancelled' ? 'danger' : 'success' }}">{{ match ($entry->status) { 'approved' => 'معتمد', 'cancelled' => 'ملغى أثناء التجهيز', default => 'مسودة' } }}</span></td>
                <td class="record-action"><a class="btn btn-outline btn-sm" href="{{ route('inventory.opening-stock.show', $entry) }}">عرض المستند</a></td>
            </tr>
        @empty
            <tr class="record-empty"><td colspan="8" class="empty"><strong>لا توجد بضاعة افتتاحية بعد</strong><p>ابدأ بإدخال البضاعة الموجودة لديك.</p><button type="button" class="btn btn-primary" onclick="openQuickOpeningStockModal()">إدخال بضاعة</button></td></tr>
        @endforelse
    </tbody></table></div><x-pagination :paginator="$entries" /></section>
</div>
@include('inventory.opening-stock.partials.form')
@endsection
