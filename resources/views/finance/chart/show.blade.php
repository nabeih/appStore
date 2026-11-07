@extends('layouts.app')
@section('title','حركات حساب '.$entry->name)
@include('sales.partials.assets')
@section('content')
<div class="sale-page"><header class="sale-header"><h1>{{ $entry->code }} — {{ $entry->name }}</h1><a class="btn btn-outline" href="{{ route('finance.chart.index',['branch_id'=>$branchId]) }}">شجرة الحسابات</a></header>
@if(session('success'))<p class="sale-notice">{{ session('success') }}</p>@endif
<section class="sale-panel"><p>{{ \App\Actions\Finance\ChartOfAccounts::GROUPS[$entry->account_group]??'غير مصنف' }} · {{ $entry->normal_balance==='credit'?'طبيعة دائنة':'طبيعة مدينة' }} · {{ $entry->is_posting?'حساب تفصيلي':'حساب تجميعي' }}</p><h2>الرصيد المباشر لجميع التواريخ: <bdi>{{ \App\Support\Sales\Money::format($entry->balance_minor) }}</bdi> ₪</h2><p class="sale-muted">الرصيد ضمن نطاق الفرع المطلوب. التجميعي لا يستقبل قيودًا؛ أرصدة حساباته الفرعية في الشجرة.</p></section>
<form class="sale-panel sale-filters" method="get"><input type="hidden" name="branch_id" value="{{ $branchId }}"><label>من<input type="date" name="from" value="{{ request('from') }}"></label><label>إلى<input type="date" name="to" value="{{ request('to') }}"></label><button class="btn btn-outline">عرض الحركات</button></form>
<section class="sale-panel"><h2>الحركات المباشرة للحساب ضمن الفترة</h2><table class="sale-table sale-mobile-table"><thead><tr><th>التاريخ / القيد</th><th>الوصف</th><th>مدين</th><th>دائن</th></tr></thead><tbody>@forelse($lines as $line)<tr><td>{{ $line->entry->transaction_date->format('Y-m-d') }}<small>{{ $line->entry->number }}</small></td><td data-label="الوصف">{{ $line->entry->description }}</td><td data-label="مدين">{{ \App\Support\Sales\Money::format($line->debit_minor) }}</td><td data-label="دائن">{{ \App\Support\Sales\Money::format($line->credit_minor) }}</td></tr>@empty<tr><td colspan="4" class="sale-empty">لا توجد قيود مباشرة ضمن الفترة.</td></tr>@endforelse</tbody></table><x-pagination :paginator="$lines" /></section>
@if($installed)@can('sales.manage_accounts')<section class="sale-panel"><h2>بيانات الحساب وحالته</h2><p class="sale-muted">تغيير التصنيف أو الأب بعد وجود قيود أو حسابات فرعية، وتعطيل حساب تاريخي أو مستخدم بالنظام، ممنوعان.</p>@include('finance.chart.form')</section>@endcan @endif
</div>
@endsection
