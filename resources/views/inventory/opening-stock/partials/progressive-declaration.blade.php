<details class="sale-notice" data-progressive-opening @if(isset($declarationId)) id="{{ $declarationId }}" @endif>
<summary>دليل رصيد افتتاحي متأخر أثناء التشغيل التدريجي</summary>
<p>هذا اعتماد لقطة جرد حقيقية أخذت عند الانتقال أو قبله، لصنف لم يسجل له رصيد أو حركة بالفرع. ليس جرد اليوم أو شراءً جديدًا. إن وُجدت حركات خارج التطبيق غير مسجلة، لا تعتمد هذه اللقطة.</p>
<label>وقت أخذ لقطة الجرد الأصلي<input type="datetime-local" data-progressive-counted></label>
<label>مرجع الدليل وشمول الحركات<textarea data-progressive-evidence maxlength="2000"></textarea></label>
<label class="sale-check"><input type="checkbox" data-progressive-confirmed> أؤكد أن الكمية تخص اللقطة الأصلية، وأن الحركات الفاصلة موثقة، ولا توجد مشتريات أو مبيعات خارجية ناقصة لهذه الأصناف.</label>
<a href="{{ route('inventory.operation.index') }}">مراجعة تاريخ الانتقال وحالة التشغيل</a>
</details>
