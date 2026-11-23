@extends('layouts.app')
@section('title','تسوية مديونية مورد سابقة')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/purchases/invoices.css') }}">@endpush
@section('content')
<div class="trade-page" dir="rtl">
    <header class="trade-heading"><div><a href="{{ route('suppliers.show',$settlement->supplier_id) }}">كشف المورد</a><h1>تسوية مديونية مورد سابقة {{ $settlement->number }}</h1><p>{{ $settlement->supplier->name }} — الفرع #{{ $settlement->branch_id }} — {{ ['draft'=>'مسودة','approved'=>'معتمدة','reversed'=>'معكوسة'][$settlement->status] }}</p></div><button type="button" data-print class="btn btn-outline">طباعة المستند وسندات الصرف</button></header>
    @if(session('success'))<p class="trade-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="trade-errors" role="alert">{{ $errors->first() }}</p>@endif
    <div class="trade-totals">
        <div class="card"><small>المديونية السابقة ₪</small><strong>{{ \App\Support\Sales\Money::format($settlement->amount_minor) }}</strong></div>
        <div class="card"><small>المسدد ₪</small><strong>{{ \App\Support\Sales\Money::format($settlement->paid_minor) }}</strong></div>
        <div class="card"><small>المتبقي المعتمد ₪</small><strong>{{ \App\Support\Sales\Money::format($settlement->status==='approved' ? $settlement->amount_minor-$settlement->paid_minor : 0) }}</strong></div>
    </div>
    <section class="card trade-panel">
        <p>نشوء الدين: {{ $settlement->original_date->format('Y-m-d') }} | بداية التتبع: {{ $settlement->tracking_started_on->format('Y-m-d') }} | تاريخ القيد: {{ $settlement->posting_date->format('Y-m-d') }}</p>
        <p>المرجع: {{ $settlement->reference }}</p><p>{{ $settlement->notes }}</p>
        @if($settlement->hasEvidence())
        <a href="{{ route('suppliers.debts.evidence',$settlement) }}" class="btn btn-outline">تنزيل المستند الداعم</a>
        @else
        <p class="trade-muted">لم يُرفق مستند إثبات؛ إرفاق المستند اختياري.</p>
        @endif
        @can('finance.view')<a href="{{ route('finance.suppliers.show',[$settlement->supplier_id,'branch_id'=>$settlement->branch_id]) }}" class="btn btn-outline">مراجعة الذمة والقيود</a>@endcan
        @if($settlement->status==='draft')
            <h2>معاينة الأثر قبل الاعتماد</h2><p>مدين مقابل الأرصدة الافتتاحية {{ \App\Support\Sales\Money::format($settlement->amount_minor) }} ₪، دائن ذمم الموردين بالقيمة نفسها. لا صرف نقدي ولا حركة مخزون.</p>
            <p>هذه المعالجة فقط لالتزام افتتاحي غير مسجل. إذا لم تتضح طبيعة الدين أو حسابه المقابل، لا تعتمد واطلب مراجعة محاسبية.</p>
            @foreach($review['issues'] as $issue)<p class="trade-errors">{{ $issue }}</p>@endforeach
            @can('finance.transition_review')
            <form method="post" action="{{ route('suppliers.debts.approve',$settlement) }}">@csrf
                <input type="hidden" name="revision" value="{{ $review['revision'] }}">
                <label><input type="checkbox" name="opening_confirmed" value="1" required> راجعت المرجع وبيانات الدين والمرفق إن وُجد، وأؤكد أن هذا التزام افتتاحي قائم قبل بداية التتبع، ومعالجته مقابل الأرصدة الافتتاحية مناسبة.</label>
                <label><input type="checkbox" name="unrecorded_confirmed" value="1" required> راجعت كشف المورد والفواتير والدفتر، وهذا المبلغ لم يُسجل سابقًا.</label>
                <button type="submit" class="btn btn-primary" @disabled($review['issues']!==[])>اعتماد المديونية المعروضة</button>
            </form>
            @endcan
        @endif
    </section>
    @if($settlement->status==='approved' && $settlement->paid_minor < $settlement->amount_minor)
    @can('finance.supplier_pay')
    <form class="card trade-panel" method="post" action="{{ route('suppliers.debts.pay',$settlement) }}">@csrf
        <h2>سداد المديونية السابقة</h2>
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid',(string) \Illuminate\Support\Str::uuid()) }}">
        <label>المبلغ ₪<input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></label>
        <label>حساب الصرف<select name="account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int)old('account_id')===$account->id)>{{ $account->name }} #{{ $account->id }}</option>@endforeach</select></label>
        <label>تاريخ السداد<input type="date" name="payment_date" value="{{ old('payment_date',\App\Support\Sales\OperationContext::today()) }}" required></label>
        <label>مرجع السداد<input name="reference" value="{{ old('reference') }}" maxlength="100"></label>
        <label>ملاحظات<input name="notes" value="{{ old('notes') }}" maxlength="500"></label>
        <label><input type="checkbox" name="confirmed" value="1"> أؤكد تنفيذ التحويل عند اختيار البنك أو المحفظة.</label>
        <button class="btn btn-primary" type="submit">تسجيل سند صرف</button>
    </form>
    @endcan
    @endif
    <section class="card trade-panel"><h2>سندات الصرف</h2><div class="table-responsive"><table><thead><tr><th>السند / التاريخ</th><th>حساب الصرف</th><th>المبلغ ₪</th><th>الحالة والإجراء</th></tr></thead><tbody>
    @forelse($payments as $payment)
        <tr id="supplier-payment-{{ $payment->id }}"><td>SP-{{ str_pad((string)$payment->id,6,'0',STR_PAD_LEFT) }} — {{ $payment->payment_date->format('Y-m-d') }}<small>{{ $payment->reference }} — {{ $payment->creator?->name }}</small></td><td>{{ $payment->account?->name }}</td><td>{{ $payment->amount }}</td><td>{{ $payment->status==='reversed' ? 'معكوس' : 'معتمد' }}
        @can('finance.reverse')@if($payment->status==='approved')<form method="post" action="{{ route('suppliers.debts.payment-reverse',$payment) }}">@csrf<label>سبب عكس السداد<input name="reason" minlength="3" maxlength="1000" required></label><button class="btn btn-outline">عكس السند دون حذفه</button></form>@endif
    @endcan</td></tr>
    @empty<tr><td colspan="4">لا توجد دفعات. اعتماد المديونية وحده لا يصرف مالًا.</td></tr>@endforelse
    </tbody></table></div></section>
    @if($settlement->status==='approved')@can('finance.transition_review')@can('finance.reverse')
    <form class="card trade-panel" method="post" action="{{ route('suppliers.debts.reverse',$settlement) }}">@csrf
        <h2>عكس المديونية</h2><p>اعكس جميع سندات السداد أولًا. يبقى المستند الأصلي والقيود محفوظين، ويُسجل العكس في الفترة المفتوحة الحالية.</p>
        <label>سبب العكس<input name="reason" minlength="3" maxlength="1000" required></label><button class="btn btn-outline" @disabled($settlement->paid_minor>0)>عكس التسوية رسميًا</button>
    </form>
    @endcan
    @endcan
    @endif
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/admin/js/pages/purchases/document.js') }}" defer></script>@endpush
