@if($allocations->isNotEmpty())
<section class="sale-panel"><h2>جدول توزيع الاستفادة — دون دفعات شهرية</h2>
<p>الاعتماد يثبت أصل استفادة ومستحقًا. سند الصرف يسجل الدفع الفعلي. الحصة الشهرية تدخل مصروف الفترة عند استحقاقها فقط.</p>
<table class="sale-table sale-mobile-table"><thead><tr><th>الشهر</th><th>فترة الاستفادة</th><th>الحصة ₪</th><th>الاستحقاق والحالة</th></tr></thead><tbody>
@foreach($allocations as $allocation)<tr><td>{{ $allocation->month->format('Y-m') }}</td><td data-label="فترة الاستفادة">{{ $allocation->period_start->format('Y-m-d') }} — {{ $allocation->period_end->format('Y-m-d') }}</td><td data-label="الحصة">{{ \App\Support\Sales\Money::format($allocation->amount_minor) }}</td><td data-label="الاستحقاق والحالة">{{ $allocation->due_date->format('Y-m-d') }} — {{ $allocation->posted_at ? ($expense->status==='reversed' ? 'مرحلة ثم معكوسة' : 'مرحلة') : (in_array($expense->status,['reversed','cancelled']) ? 'ملغاة' : 'مجدولة') }}</td></tr>@endforeach
</tbody></table>
@if($expense->status==='approved')@can('finance.expenses.approve')<form action="{{ route('finance.expenses.recognize',$expense) }}" data-simple-form>@include('sales.partials.feedback')<fieldset><p>يرحل المستحق فقط؛ الفترة المغلقة توقف العملية دون تغيير التاريخ. لا صرف نقدي إضافي.</p><button class="btn btn-primary">ترحيل الحصص المستحقة الآن</button></fieldset></form>@endcan @endif
<p>المسودة تعدل بإلغائها وإدخال مستند جديد. تغيير عقد معتمد أو الاسترداد الجزئي يحتاج مراجعة مستقلة. العكس الكامل موثق، ويستلزم عكس دفعاته أولًا.</p>
</section>
@endif
