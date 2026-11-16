@extends('layouts.app')
@section('title','تصحيح بند '.$source->number)
@include('sales.partials.assets')
@section('content')
<div class="sale-page sale-panel"><h1>تصحيح بند — {{ $source->number }}</h1><p>{{ $line->product_name }} — {{ $line->color_name }} / {{ $line->size_name }} — {{ $line->sku }}</p>
<p class="sale-notice">هذا تصحيح خطأ إدخال موثق بتاريخ اليوم، مع إبقاء المستند والقيود الأصلية. رجوع البضاعة الفعلي يُعالج بمستند مرتجع. لا إعادة احتساب تلقائية لمصادر تكلفة مختلطة.</p>
<form method="get" class="sale-grid"><label>الكمية الصحيحة في المستند<input name="quantity" type="number" min="0" max="1000000" required value="{{ request('quantity',$defaults['quantity']) }}"></label><label>تكلفة الوحدة الصحيحة ₪<input name="unit_cost" inputmode="decimal" required value="{{ request('unit_cost',$defaults['unit_cost']) }}"></label><label>سبب التصحيح ودليله<input name="reason" minlength="5" maxlength="1000" required value="{{ request('reason') }}"></label><button class="btn btn-outline">معاينة أثر التصحيح</button></form>
@if($problem)<p role="alert" class="sale-notice">{{ $problem }}</p><a href="{{ route('inventory.operation.index',['branch_id'=>$source->branch_id]) }}">مراجعة الجاهزية</a>@endif
@if($plan)
<h2>الأثر بتاريخ {{ $plan['date'] }}</h2><p>المخزون بعد التصحيح: {{ $plan['stock_after']['quantity'] }} قطعة، {{ \App\Support\Sales\Money::format($plan['stock_after']['value_minor']) }} ₪.</p>
<dl>@foreach(['inventory_delta_minor'=>'فرق قيمة المخزون','cogs_delta_minor'=>'فرق تكلفة المبيعات بقيد مستقل','payable_delta_minor'=>'فرق ذمة المورد','document_delta_minor'=>'فرق قيمة المستند'] as $key=>$label)<dt>{{ $label }}</dt><dd>{{ \App\Support\Sales\Money::format($plan[$key]) }} ₪</dd>@endforeach</dl>
<p>الإشارة الموجبة زيادة والسالبة تخفيض. لا قبض أو دفع ضمن هذه العملية، ولا تغيير لأسعار البيع أو لقطات الفواتير الأصلية.</p>
<form action="{{ route('inventory.document-corrections.store',[$kind,$source->id,$line->id]) }}" data-simple-form>@include('sales.partials.feedback')<fieldset><input type="hidden" name="quantity" value="{{ $plan['quantity'] }}"><input type="hidden" name="unit_cost" value="{{ $plan['unit_cost'] }}"><input type="hidden" name="reason" value="{{ request('reason') }}"><input type="hidden" name="revision" value="{{ $plan['revision'] }}"><input type="hidden" name="operation_uuid" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><label class="sale-check"><input type="checkbox" name="confirmed" value="1" required> راجعت الأثر وأؤكد أنه تصحيح خطأ إدخال وليس رجوع بضاعة.</label><button class="btn btn-primary">اعتماد التصحيح المعروض</button></fieldset></form>
@endif
</div>
@endsection
