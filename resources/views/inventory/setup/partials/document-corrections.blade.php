@if($correctionDocument->status==='approved' && auth()->user()->can('sales.view_cost'))
<section class="sale-panel os-no-print"><h2>تصحيحات البنود المعتمدة</h2><p>يبقى الأصل محفوظًا. التصحيح يسجل الفرق فقط، ولا يُستخدم لإرجاع البضاعة فعليًا.</p>
@if(auth()->user()->can($correctionKind==='purchase'?'purchases.manage':'inventory.correct_setup') && auth()->user()->can($correctionKind==='purchase'?'finance.purchase_post':'inventory.valuation_review'))
@foreach($correctionDocument->items as $line)<p>{{ $line->product_name }} — {{ $line->color_name }} / {{ $line->size_name }} <a class="btn btn-outline" href="{{ route('inventory.document-corrections.show',[$correctionKind,$correctionDocument->id,$line->id]) }}">تصحيح بند</a></p>@endforeach
@endif
@foreach(\App\Models\AuditEvent::where('subject_type',$correctionDocument->getMorphClass())->where('subject_id',$correctionDocument->id)->where('action','inventory.document_corrected')->orderBy('id')->get() as $correction)
<article><h3>تصحيح #{{ $correction->id }} — {{ $correction->data['sku'] }}</h3><p>{{ $correction->created_at }} — المستخدم #{{ $correction->user_id }} — {{ $correction->reason }}</p><p>الكمية المعتمدة بعد التصحيح {{ $correction->data['quantity'] }}، تكلفة الوحدة {{ $correction->data['unit_cost'] }}. فرق المخزون {{ \App\Support\Sales\Money::format($correction->data['inventory_delta_minor']) }}، فرق تكلفة المبيعات {{ \App\Support\Sales\Money::format($correction->data['cogs_delta_minor']) }}، فرق ذمة المورد {{ \App\Support\Sales\Money::format($correction->data['payable_delta_minor']) }} ₪.</p></article>
@endforeach
</section>
@endif
