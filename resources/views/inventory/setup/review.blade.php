@extends('layouts.app')
@section('title','مراجعة جاهزية المخزون')
@include('sales.partials.assets')
@section('content')
<div class="sale-page"><header class="sale-header"><h1>مراجعة جاهزية المخزون</h1><div class="sale-actions"><a class="btn btn-outline" href="{{ route('inventory.review.export',request()->query()) }}">تصدير التقرير</a><a class="btn btn-outline" href="{{ route('inventory.index') }}">المخزون</a></div></header>
<p class="sale-notice">التقرير للقراءة والمراجعة. غياب القيمة لا يعني تكلفة صفرًا. لا نستنتج تكلفة افتتاحية من سعر بيع أو سعر شراء حالي، ولا نعدّل الكمية أو القيود تلقائيًا.</p>
<section class="sale-panel" data-search-region="readiness">
@include('search.partials.filters',['searchKind'=>'readiness','placeholder'=>'اسم المنتج أو كوده أو SKU'])
<table class="sale-table sale-mobile-table"><thead><tr><th>الصنف / الفرع</th><th>الكمية والحركات</th><th>القيمة والأدلة</th><th>الجاهزية / المراجعة</th></tr></thead><tbody>
@foreach($entries as $stock)
@php($review = $stock->readiness)
<tr><td>{{ $stock->productVariant->product?->name }}<small>{{ $stock->productVariant->sku }} — {{ $stock->productVariant->color?->name }} / {{ $stock->productVariant->size?->name }} — {{ $stock->branch->name }}</small></td>
<td data-label="الكمية والحركات">الحالية {{ $stock->quantity }} / الحركات {{ $review['history_quantity'] }}<small>الفرق: {{ $review['difference'] }}</small></td>
<td data-label="القيمة والأدلة">المسجلة: {{ $stock->value_minor === null ? 'غير معتمدة' : \App\Support\Sales\Money::format((int)$stock->value_minor).' ₪' }}
<small>إعادة البناء: {{ $review['reconstructed_value_minor'] === null ? 'الدليل غير مكتمل أو مختلف' : \App\Support\Sales\Money::format($review['reconstructed_value_minor']).' ₪' }}</small>
<details><summary>المستندات المصدرية ({{ count($review['sources']) }})</summary>@forelse($review['sources'] as $source)<p class="sale-muted">حركة #{{ $source['movement_id'] }} — {{ $source['kind'] }} — كمية {{ $source['quantity'] }} @if($source['url'])<a href="{{ $source['url'] }}">{{ $source['number'] }}</a>@else مصدر {{ class_basename($source['source_type']) }} #{{ $source['source_id'] }}@endif<br>الترحيل المالي: {{ $source['financial_posted'] ? 'قيد مسجل؛ يلزم تطابق الإجمالي' : 'غير مثبت في الدفتر' }} — {{ $source['document_date'] ?? 'تاريخ غير موثق' }}@foreach($source['issues'] as $issue)<br>{{ $issue }}@endforeach</p>@empty<p>لا توجد حركات مصدرية؛ يلزم دليل المالك.</p>@endforelse</details></td>
<td data-label="الجاهزية / المراجعة">
@php($labels = ['history_review'=>'دليل الحركة غير مكتمل','reviewed'=>'قيمة موثقة','reconstructable'=>'قيمة قابلة لإعادة البناء من المستندات','owner_evidence'=>'تحتاج دليلًا أو قرارًا من المالك','quantity_review'=>'فرق كمية يحتاج مراجعة مستقلة','value_conflict'=>'تعارض قيمة يحتاج مراجعة المصدر'])
<p class="{{ in_array($review['state'],['quantity_review','value_conflict']) ? 'sale-errors' : 'sale-muted' }}">{{ $labels[$review['state']] }}</p>
<p>{{ $review['recommended_action'] }}</p>@foreach($review['issues'] as $issue)<small>{{ $issue }}</small>@endforeach
@if($stock->value_minor === null && $review['difference'] === 0 && $review['reconstructed_value_minor'] !== null)
<details><summary>إعداد مراجعة واعتماد قيمة</summary><form action="{{ route('inventory.review.baseline',$stock) }}" data-simple-form>@include('sales.partials.feedback')<fieldset>
<input type="hidden" name="revision" value="{{ \App\Support\Inventory\Setup\InventoryState::revision($stock,$stock->productVariant,$stock->productVariant->product) }}">
<label>أساس المراجعة<select name="basis">@if($review['reconstructed_value_minor'] !== null)<option value="documents">المستندات الكاملة</option>@endif<option value="owner_evidence">دليل أو قرار موثّق من المالك</option></select></label>
<label>القيمة الإجمالية المعتمدة<input name="approved_value" inputmode="decimal" required value="{{ $review['reconstructed_value_minor'] === null ? '' : \App\Support\Sales\Money::format($review['reconstructed_value_minor']) }}"></label>
<label>وصف الدليل ومرجعه<textarea name="evidence" minlength="5" maxlength="1000" required></textarea></label><label>سبب الاعتماد<input name="reason" minlength="3" maxlength="500" required></label>
<p class="sale-muted">هذا الإجراء يوثق قيمة الرصيد القائم فقط؛ لا يضيف كمية أو قيد افتتاحي جديد. الأثر المالي القديم يُراجع في كشف الانتقال.</p>
<label class="sale-check"><input name="confirmed" type="checkbox" value="1" required> راجعت المبلغ والمستندات وأوافق على توثيق القيمة.</label><button class="btn btn-outline">اعتماد القيمة بعد المراجعة</button></fieldset></form></details>
@endif</td></tr>@endforeach</tbody></table><x-pagination :paginator="$entries" /></section>
@can('inventory.close_setup')
@include('inventory.setup.partials.completion')
@endcan</div>
@endsection
