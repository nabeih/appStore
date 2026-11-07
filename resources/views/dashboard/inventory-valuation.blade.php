@if($valuation!==null)
<section class="commerce-card dashboard-live-recent" data-inventory-valuation>
<header class="commerce-header"><div><p class="commerce-eyebrow">تقييم المخزون الحالي</p><h2>قيمة المخزون بالتكلفة</h2></div>
<form method="get" class="commerce-filter"><label>الفرع<select name="branch_id"><option value="">جميع الفروع المسموحة</option>@foreach($allowedBranches as $branch)<option value="{{ $branch->id }}" @selected($branchId===$branch->id)>{{ $branch->name }}</option>@endforeach</select></label><button class="btn btn-outline">عرض</button></form></header>
<strong class="dashboard-live-number">{{ $valuation['value_minor']===null?'غير مكتملة':\App\Support\Sales\Money::format($valuation['value_minor']).' ₪' }}</strong>
<p class="commerce-muted">القيمة الدفترية المخزنية بالتكلفة وفق المتوسط المرجح الحالي، تشمل المخزون المتاح وقيد الفحص، بما فيه المحجوز المملوك للمحل. لا تستخدم أسعار البيع ولا تخصم الحجز. وقت القراءة: <bdi>{{ $valuation['at'] }}</bdi>.</p>
@if(!$valuation['complete'])<p class="commerce-notice danger" role="status">التقييم يحتاج مراجعة: {{ $valuation['unknown_rows'] }} رصيد بقيمة مجهولة، {{ $valuation['invalid_rows'] }} رصيد غير صالح، {{ $valuation['history_issues'] }} اختلافًا مع الحركات. تطابق القيمة مع الدفتر غير مكتمل؛ لا تسوية تلقائية.</p>@endif
@can('finance.view')
<div class="table-responsive"><table><thead><tr><th>البند</th><th>قيمة المخزون</th><th>رصيد الدفتر</th><th>الفرق</th></tr></thead><tbody>@foreach($valuation['reconciliation'] as $code=>$row)<tr><td>{{ $code==='inventory'?'المخزون المتاح (يشمل المحجوز)':'بضاعة قيد الفحص' }}</td>@foreach(['stock_minor','ledger_minor','difference_minor'] as $field)<td>{{ $row[$field]===null?'غير مكتمل':\App\Support\Sales\Money::format($row[$field]).' ₪' }}</td>@endforeach</tr>@endforeach</tbody></table></div>
<a class="btn btn-outline" href="{{ route('finance.inventory-reconciliation',['branch_id'=>$branchId]) }}">تفاصيل المطابقة والمستندات</a>
@endcan
</section>
@endif
