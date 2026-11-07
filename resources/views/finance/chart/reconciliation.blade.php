@extends('layouts.app')
@section('title','مطابقة قيمة المخزون والدفتر')
@include('sales.partials.assets')
@section('content')
<div class="sale-page"><header class="sale-header"><h1>مطابقة قيمة المخزون والدفتر</h1><a class="btn btn-outline" href="{{ route('dashboard.index',['branch_id'=>$branchId]) }}">لوحة التحكم</a></header>
@include('dashboard.inventory-valuation',['allowedBranches'=>$branches])
<section class="sale-panel"><h2>الأرصدة وأدلة المستندات</h2><p class="sale-muted">القيمة الفارغة مجهولة وليست صفرًا. فرق كمية أو قيمة يُراجع من المستند المصدر؛ لا قيد تسوية ولا تغيير رصيد من هذا التقرير.</p>
@forelse($stocks as $stock)<details class="sale-panel"><summary>{{ $stock->productVariant->product?->name }} — {{ $stock->productVariant->sku }} — {{ $stock->branch->name }}</summary><p>كمية الرصيد {{ $stock->quantity }}، مجموع الحركات {{ $stock->readiness['history_quantity'] }}، الفرق {{ $stock->readiness['difference'] }}.</p><p>القيمة الحالية: {{ $stock->value_minor===null?'غير معتمدة':\App\Support\Sales\Money::format((int)$stock->value_minor).' ₪' }}.</p>
@foreach($stock->readiness['issues'] as $issue)<p class="sale-notice">{{ $issue }}</p>@endforeach
@foreach($stock->readiness['sources'] as $source)<p>{{ $source['number']??'حركة مصدر' }} · كمية {{ $source['quantity'] }} · قيمة الدليل {{ $source['evidence_value_minor']===null?'غير مكتملة':\App\Support\Sales\Money::format($source['evidence_value_minor']).' ₪' }} · {{ $source['document_date']??'تاريخ غير متاح' }} · {{ $source['document_status']??'حالة غير متاحة' }} · {{ $source['financial_posted']?'ترحيل مالي موجود':'الترحيل المالي غير مثبت بهذا الدليل' }}</p>@endforeach
</details>
@empty<p class="sale-muted">لا توجد أرصدة ضمن الفروع المسموحة.</p>@endforelse
<x-pagination :paginator="$stocks" /></section>
@can('inventory.valuation_review')<a class="btn btn-outline" href="{{ route('inventory.review.index',['branch_id'=>$branchId]) }}">فتح مراجعة المصادر والتصحيحات الموثقة</a>@endcan
</div>
@endsection
