@extends('layouts.app')
@section('title', 'رصيد افتتاحي '.$entry->number)
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/inventory/opening-stock.css') }}">@endpush
@section('content')
<article class="screen active os-document">
    <div class="os-page-title"><div><h1 class="section-title">مستند بضاعة افتتاحية {{ $entry->number }}</h1><p>بضاعة موجودة عند بدء العمل — بدون فاتورة شراء</p></div><div class="os-no-print"><button type="button" class="btn btn-primary" onclick="window.print()">طباعة المستند</button><a class="btn btn-outline" href="{{ route('inventory.opening-stock.index') }}">سجل المستندات</a><a class="btn btn-outline" href="{{ route('inventory.index') }}">المخزون</a></div></div>
    <div class="card os-document-meta"><span>الفرع: <strong>{{ $entry->branch->name }}</strong></span><span>تاريخ الرصيد: <strong>{{ $entry->opening_date?->format('Y-m-d') ?? '—' }}</strong></span><span>وقت الاعتماد: {{ $entry->approved_at?->format('Y-m-d H:i') ?? '—' }}</span><span>أدخل بواسطة: {{ $entry->creator?->name ?? 'غير محدد' }}</span><span class="badge {{ $entry->status === 'cancelled' ? 'danger' : 'success' }}">{{ match ($entry->status) { 'approved' => 'معتمد', 'cancelled' => 'ملغى أثناء التجهيز', default => 'مسودة' } }}</span></div>
    @if($entry->notes)<div class="card" style="padding:16px;white-space:pre-wrap">{{ $entry->notes }}</div>@endif
    <div class="card"><div class="table-responsive mobile-records-wrapper"><table class="mobile-records" role="table"><thead><tr><th>#</th><th>الكود / SKU</th><th>المنتج والتصنيف</th><th>اللون</th><th>المقاس</th><th>الكمية</th><th>تكلفة الوحدة ₪</th><th>سعر البيع ₪</th><th>قيمة البضاعة ₪</th></tr></thead><tbody>
        @foreach($entry->items as $item)
            <tr><td data-label="السطر">{{ $loop->iteration }}</td><td class="record-wide" data-label="الكود / SKU"><strong dir="ltr">{{ $item->product_code }}</strong><br><small dir="ltr">{{ $item->sku }}</small></td><td class="record-primary" data-label="المنتج">{{ $item->product_name }}<br><small>{{ $item->category_name }}</small></td><td data-label="اللون">{{ $item->color_name }}</td><td data-label="المقاس">{{ $item->size_name }}</td><td data-label="الكمية">{{ $item->quantity }}</td><td data-label="تكلفة الوحدة ₪">{{ $item->unit_cost }}</td><td data-label="سعر البيع ₪">{{ $item->selling_price }}</td><td class="record-amount" data-label="قيمة البضاعة ₪">{{ $item->line_total }}</td></tr>
        @endforeach
    </tbody><tfoot><tr><th colspan="5">الإجمالي: {{ $entry->items->count() }} صنف</th><th>{{ $entry->total_quantity }} قطعة</th><th colspan="2">قيمة البضاعة الافتتاحية</th><th>{{ $entry->total_cost }} ₪</th></tr></tfoot></table></div></div>
    <p>هذا المستند يثبت البضاعة الافتتاحية ولا يمثل عملية شراء أو سداد. تظهر تصحيحات الإدخال أدناه عند وجودها.</p>
    @if($entry->setupChanges->isNotEmpty())
        <div class="card" style="padding:16px;">
            <h3 style="font-size:1rem;">تصحيحات إدخال البضاعة</h3>
            <div class="table-responsive mobile-records-wrapper"><table class="mobile-records" role="table"><thead><tr><th>التاريخ</th><th>الصنف</th><th>الإجراء</th><th>قبل التصحيح</th><th>بعد التصحيح</th><th>الملاحظة</th></tr></thead><tbody>
                @foreach($entry->setupChanges as $change)
                    <tr><td data-label="التاريخ">{{ $change->created_at->format('Y-m-d H:i') }}</td><td class="record-primary" data-label="الصنف">{{ $change->before_state['product_name'] }}<br>{{ $change->before_state['sku'] }}</td><td class="record-status">{{ $change->operation === 'deleted' ? 'حذف سطر' : 'تعديل' }}</td><td data-label="قبل التصحيح">الكمية: {{ $change->before_state['quantity'] }}<br>التكلفة: {{ $change->before_state['average_cost'] }}<br>البيع: {{ $change->before_state['selling_price'] }}</td><td data-label="بعد التصحيح">@if($change->after_state){{ $change->after_state['product_name'] }}<br>{{ $change->after_state['sku'] }}<br>الكمية: {{ $change->after_state['quantity'] }}<br>التكلفة: {{ $change->after_state['average_cost'] }}<br>البيع: {{ $change->after_state['selling_price'] }}@else حُذف من مخزون الفرع @endif</td><td class="record-wide" data-label="الملاحظة">{{ $change->reason }}</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
    @endif
</article>
@endsection