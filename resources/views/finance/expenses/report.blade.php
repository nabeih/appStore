@extends('layouts.app')
@section('title','المصروفات المعترف بها والدفع الفعلي')
@include('sales.partials.assets')
@section('content')
<div class="sale-page"><header class="sale-header"><h1>المصروفات المعترف بها والدفع الفعلي</h1><a href="{{ route('finance.expenses.index') }}">المصروفات</a></header>
@include('finance.partials.notice')
<form class="sale-panel sale-filters" method="get"><input type="hidden" name="branch_id" value="{{ $filters['branch_id'] }}"><label>من<input type="date" name="from" value="{{ $filters['from'] }}" required></label><label>إلى<input type="date" name="to" value="{{ $filters['to'] }}" required></label><button class="btn btn-primary">عرض الفترة</button><button class="btn btn-outline" name="export" value="xlsx">تنزيل Excel</button><button class="btn btn-outline" name="export" value="json">عرض JSON</button></form>
<section class="sale-panel"><p>الفرع #{{ $filters['branch_id'] }}. حصة المصروف حسب القيود المرحلة، والدفع حسب الحساب الفعلي؛ تعريف حساب في الشجرة لا يغير تصنيف الترحيل تلقائيًا.</p>
<div class="sale-summary"><div>مصروف الفترة المعترف به<strong>{{ \App\Support\Sales\Money::format($report['expenses_minor']) }} ₪</strong></div><div>صافي الدفع الفعلي في الفترة<strong>{{ \App\Support\Sales\Money::format($report['cash_paid_minor']) }} ₪</strong></div><div>رصيد أصل الاستفادة بنهاية الفترة<strong>{{ \App\Support\Sales\Money::format($report['prepaid_remaining_minor']) }} ₪</strong></div><div>حصص مستحقة غير مرحلة<strong>{{ $report['due_allocations'] }}</strong></div></div>
<table class="sale-table"><thead><tr><th>التصنيف</th><th>المعترف به</th><th>صافي المدفوع</th></tr></thead><tbody>@forelse($report['rows'] as $row)<tr><td>{{ ['rent'=>'إيجار','utilities'=>'كهرباء ومياه واتصالات','maintenance'=>'صيانة','transport'=>'نقل','supplies'=>'مستلزمات','other'=>'أخرى'][$row['category']] ?? $row['category'] }}</td><td>{{ \App\Support\Sales\Money::format($row['expenses_minor']) }}</td><td>{{ \App\Support\Sales\Money::format($row['cash_paid_minor']) }}</td></tr>@empty<tr><td colspan="3">لا قيود مصروفات مطابقة لهذه الفترة.</td></tr>@endforelse</tbody></table>
<p>هذا كشف مصروفات وأصل استفادة، وليس ميزانية عمومية كاملة أو اعتمادًا لصافي الربح. العكس يظهر بتاريخ قيده ولا يمحو الفترة الأصلية.</p></section></div>
@endsection
