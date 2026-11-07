@extends('layouts.app')
@section('title','شجرة الحسابات')
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/finance/chart.css') }}">@endpush
@section('content')
<div class="sale-page chart-page"><header class="sale-header"><div><h1>شجرة الحسابات</h1><p class="sale-muted">نفس حسابات الدفتر الحالي، مرتبة دون تغيير القيود أو الأرصدة.</p></div><a class="btn btn-outline" href="{{ route('finance.treasury.index') }}">الصناديق</a></header>
@if(session('success'))<p class="sale-notice" role="status">{{ session('success') }}</p>@endif
@if(!$installed)<p class="sale-notice">العرض متاح من الحسابات القائمة. الإضافة والتعديل ينتظران تطبيق تحديث شجرة الحسابات المعتمد.</p>@endif
<form class="sale-panel sale-filters" method="get"><label>رمز الحساب أو اسمه<input name="q" maxlength="100" value="{{ request('q') }}"></label><label>الفرع<select name="branch_id"><option value="">جميع الفروع المسموحة</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId===$branch->id)>{{ $branch->name }}</option>@endforeach</select></label><button class="btn btn-primary">بحث وعرض</button><a class="btn btn-outline" href="{{ route('finance.chart.index') }}">مسح</a></form>
<p class="sale-muted">الرصيد بإشارة مدين موجبة ودائن سالبة، ضمن الفرع المحدد. رصيد التجميعي يشمل فروعه. الحسابات المستخدمة بالنظام محمية؛ الحذف غير متاح.</p>
<div class="chart-tree">@foreach($groups as $group)<details class="sale-panel chart-group" open><summary>{{ $group['label'] }}</summary><ul>@forelse($group['nodes'] as $node)@include('finance.chart.node',['node'=>$node])@empty<li class="sale-muted">لا توجد حسابات مطابقة في هذه المجموعة.</li>@endforelse</ul></details>@endforeach</div>
@if($accounts->contains(fn($account)=>$account->account_group===null))<p class="sale-errors">توجد حسابات غير مصنفة تحتاج مراجعة قبل تعديل الشجرة.</p>@endif
@if($installed)@can('sales.manage_accounts')<section class="sale-panel"><h2>إضافة حساب رئيسي أو فرعي</h2><p class="sale-muted">الحساب التجميعي لتنظيم الحسابات فقط؛ لا يستقبل قيودًا. حسابات الصناديق والبنوك تُعرّف من شاشة الحسابات المالية الحالية. إنشاء حساب هنا لا يغيّر حسابات الترحيل التي تستخدمها مستندات البيع والشراء والمصروفات تلقائيًا.</p>
@include('finance.chart.form',['entry'=>null])
</section>@endcan @endif
</div>
@endsection
