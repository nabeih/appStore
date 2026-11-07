@extends('layouts.app')
@section('title','حالة تشغيل المحل')
@include('sales.partials.assets')
@section('content')
<div class="sale-page"><header class="sale-header"><div><h1>حالة تشغيل المحل</h1><p class="sale-muted">فحص مباشر للجاهزية، دون تعديل أرصدة أو ترحيل فروق.</p></div><a class="btn btn-outline" href="{{ route('inventory.review.index',['branch_id'=>$branch->id]) }}">إنهاء الإعداد والانتقال للتشغيل الكامل</a></header>
<form class="sale-panel sale-filters" method="get"><label>الفرع<select name="branch_id">@foreach($branches as $option)<option value="{{ $option->id }}" @selected($option->id===$branch->id)>{{ $option->name }}</option>@endforeach</select></label><label>اسم الصنف أو SKU<input name="q" maxlength="100" value="{{ $filters['q']??'' }}"></label><label>الأصناف<select name="status"><option value="">كل الأصناف</option><option value="ready" @selected(($filters['status']??'')==='ready')>الجاهزة والمتاحة</option><option value="not_ready" @selected(($filters['status']??'')==='not_ready')>غير الجاهزة أو غير المتاحة</option></select></label><button class="btn btn-primary">فحص جاهزية التشغيل</button></form>
<section class="sale-panel" data-operation-state="{{ $report['state'] }}"><h2>{{ ['setup'=>'إعداد فقط — البيع ممنوع','progressive'=>'تشغيل تدريجي','full'=>'تشغيل كامل'][$report['state']] }} — {{ $branch->name }}</h2>
@if($report['activation'])<p>تاريخ الانتقال: <bdi>{{ $report['activation']['at'] }}</bdi>، اعتمده المستخدم #{{ $report['activation']['by'] }}.</p>@endif
@if($report['state']==='progressive')<p class="sale-notice">{{ \App\Actions\Inventory\ProgressiveOperation::WARNING }}</p>@endif
<div class="sale-summary"><div>أصناف جاهزة ومتاحة<strong>{{ $report['ready_count'] }}</strong></div><div>تحتاج مراجعة أو غير متاحة<strong>{{ $report['not_ready_count'] }}</strong></div><div>حسابات قبض جاهزة<strong>{{ collect($report['accounts'])->where('ready',true)->count() }}</strong></div></div>
<p class="sale-muted">مطابقة الدفتر مع الحركات المثبتة: فرق غير معزول {{ \App\Support\Sales\Money::format($report['stock_proof']['unexplained_financial_minor']) }} ₪. قد يبقى فرق إجمالي معروف من مستندات غير مرحلة؛ لا يصبح أي صنف جاهزًا دون إثبات مصادره الخاصة.</p>
@foreach($report['blockers'] as $blocker)<p class="sale-notice">{{ $blocker }}</p>@endforeach
@if($report['state']==='setup')
<form action="{{ route('inventory.operation.activate',$branch) }}" data-simple-form>@include('sales.partials.feedback')<fieldset>
<input name="revision" type="hidden" value="{{ $report['revision'] }}"><label>سبب بدء التشغيل<input name="reason" required minlength="5" maxlength="1000"></label>
<label class="sale-check"><input type="checkbox" name="confirmed" value="1" required> راجعت الأصناف والحسابات الجاهزة وغير الجاهزة وأعتمد تشغيل الجاهز فقط.</label>
<label class="sale-check"><input type="checkbox" name="external_movements_confirmed" value="1" required> راجعت الحركات المسجلة وخارج التطبيق؛ لن أبيع صنفًا له حركة ناقصة، ولن أدخل جرد اليوم كرَصيد قديم.</label>
<button class="btn btn-primary" @disabled(!$report['can_activate'])>بدء التشغيل التدريجي</button>
</fieldset></form>@endif
</section>
<nav class="sale-actions"><a class="btn btn-outline" href="{{ route('inventory.operation.index',['branch_id'=>$branch->id,'status'=>'not_ready']) }}#operation-stocks">عرض الأصناف غير الجاهزة</a><a class="btn btn-outline" href="#operation-accounts">عرض الحسابات غير الجاهزة</a><a class="btn btn-outline" href="#operation-parties">العملاء والموردون</a></nav>
<section class="sale-panel" id="operation-stocks"><h2>جاهزية الأصناف</h2>
@forelse($stocks as $row)<details class="sale-panel"><summary>{{ $row['name'] }} — {{ $row['sku'] }} — {{ $row['ready']?($row['available']>0?'جاهز':'مطابق، الكمية مستنفدة أو محجوزة'):'غير جاهز' }}</summary>
<p>الكمية {{ $row['quantity'] }}، المتاح بعد الحجز {{ $row['available'] }}، القيمة {{ $row['value_minor']===null?'مجهولة':\App\Support\Sales\Money::format($row['value_minor']).' ₪' }}.</p>
@foreach($row['reasons'] as $reason)<p class="sale-notice">{{ $reason['message'] }}</p>@endforeach
@foreach($row['sources'] as $source)<p>{{ $source['number']??'حركة تحتاج مراجعة مصدرها' }} · {{ $source['financial_verified']?'المصدر المالي والمخزني مطابق':'الربط المالي أو المستندي غير مثبت' }}</p>@endforeach
@foreach(['zero_opening'=>'توثيق بداية صفر قبل أول شراء مسجل','zero_cost'=>'اعتماد تكلفة صفر بدليل (لا يغيّر القيمة)'] as $kind=>$label)
@if(($kind==='zero_opening'&&in_array('opening_unknown',array_column($row['reasons'],'code'),true))||($kind==='zero_cost'&&in_array('zero_cost_unconfirmed',array_column($row['reasons'],'code'),true)))
<form action="{{ route('inventory.operation.zero',[$branch,$kind,$row['inventory_id']]) }}" data-simple-form>@include('sales.partials.feedback')<fieldset><h3>{{ $label }}</h3><input type="hidden" name="revision" value="{{ \App\Support\Sales\OperationContext::hash($row) }}"><label>الدليل<input name="evidence" minlength="5" maxlength="2000" required></label><label>السبب<input name="reason" minlength="5" maxlength="1000" required></label><label class="sale-check"><input type="checkbox" name="confirmed" value="1" required> أراجع الدليل وأؤكد أن الصفر حقيقي وليس بديلًا عن قيمة مجهولة أو فرق.</label><button class="btn btn-outline">حفظ الاعتماد الموثق</button></fieldset></form>
@endif @endforeach
</details>@empty<p class="sale-muted">لا توجد أرصدة مطابقة للفلاتر.</p>@endforelse
<x-pagination :paginator="$stocks" /></section>
<section class="sale-panel" id="operation-accounts"><h2>حسابات القبض والصرف</h2>@forelse($report['accounts'] as $account)<details class="sale-panel" @if(!$account['ready']) open @endif><summary>{{ $account['name'] }} — {{ $account['ready']?'جاهز':'يحتاج مراجعة' }}</summary><p>رصيد الدفتر {{ \App\Support\Sales\Money::format($account['balance_minor']) }} ₪، تاريخ البداية {{ $account['opening_date']??'غير معتمد' }}.</p>@foreach($account['reasons'] as $reason)<p class="sale-notice">{{ $reason }}</p>@endforeach</details>@empty<p>لا توجد حسابات قبض في الفرع.</p>@endforelse
@can('sales.manage_accounts')<a href="{{ route('finance.accounts.index',['branch_id'=>$branch->id]) }}">مراجعة الحسابات واعتماد بداية حقيقية، حتى إن كانت صفرًا</a>@endcan</section>
<section class="sale-panel" id="operation-parties"><h2>بداية ذمم العملاء والموردين</h2><p class="sale-notice">المبيعات النقدية العابرة لا تحتاج ذمة عميل. اعتماد الصفر هنا لجهة جديدة فعلًا دون معاملات مالية سابقة، وليس تصفير الدين الحالي. الأرصدة القديمة غير الصفرية تحتاج مسار انتقال معتمدًا.</p>
@foreach($report['parties'] as $kind=>$parties)<h3>{{ $kind==='customer'?'العملاء':'الموردون' }}</h3>@forelse($parties as $party)<details class="sale-panel"><summary>{{ $party['name'] }} — {{ $party['ready']?'بداية صفر موثقة':'البداية غير معتمدة' }}</summary>@if(!$party['ready'])<form action="{{ route('inventory.operation.zero',[$branch,$kind,$party['id']]) }}" data-simple-form>@include('sales.partials.feedback')<fieldset><label>دليل أن الجهة جديدة وبدايتها صفر<input name="evidence" minlength="5" maxlength="2000" required></label><label>سبب الاعتماد<input name="reason" minlength="5" maxlength="1000" required></label><label class="sale-check"><input name="confirmed" type="checkbox" value="1" required> أؤكد عدم وجود دين أو رصيد دائن سابق لدى هذه الجهة، وأن الصفر بداية حقيقية.</label><button class="btn btn-outline">اعتماد بداية صفر دون قيد</button></fieldset></form>@endif</details>@empty<p class="sale-muted">لا توجد جهات.</p>@endforelse @endforeach
</section></div>
@endsection
