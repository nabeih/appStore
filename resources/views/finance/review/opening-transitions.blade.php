<section class="sale-panel" data-opening-transitions>
<h2>اعتماد البضاعة الافتتاحية ماليًا</h2>
<p class="sale-muted">الكمية والقيمة من المستند الأصلي ولا يمكن تعديلهما هنا. كل مستند يُعتمد مستقلًا بقيد وتدقيق؛ فشل أحدها لا يلغي المستندات الناجحة. لا تتغير الكميات أو حركات المخزون أو النقد.</p>
<form data-opening-batch-form>
<fieldset>
<div class="sale-grid">
<label>تاريخ قيد مشترك (اختياري)<input name="posting_date" type="date"><small>اتركه فارغًا لاستخدام تاريخ كل مستند. التاريخ المشترك يجب أن يطابق تواريخ المستندات المحددة.</small></label>
<label>مرجع الدليل المشترك<textarea name="evidence" minlength="5" maxlength="2000" required></textarea></label>
<label>سبب الاعتماد المشترك<input name="reason" minlength="3" maxlength="1000" required></label>
</div>
<p class="sale-muted">حدد معًا المستندات التي ينطبق عليها الدليل والسبب نفسيهما فقط. «جاهز للمعاينة» فحص أولي؛ لا يغني عن مراجعة الأدلة وإعادة الفحص عند الاعتماد.</p>
<div class="table-responsive"><table class="sale-table sale-mobile-table"><thead><tr><th>تحديد</th><th>المستند / الفرع</th><th>التاريخ</th><th>القطع</th><th>القيمة ₪</th><th>الحالة وسبب التعذر / النتيجة</th></tr></thead><tbody>
@forelse($openingRows as $row)
<tr data-opening-row data-id="{{ $row['id'] }}" data-branch="{{ $row['branch_id'] }}" data-date="{{ $row['date'] }}">
<td data-label="تحديد"><input type="checkbox" data-opening-select aria-label="تحديد {{ $row['label'] }}" @disabled($row['status']!=='eligible')></td>
<td>{{ $row['label'] }} / #{{ $row['branch_id'] }}</td><td data-label="التاريخ">{{ $row['date'] }}</td><td data-label="القطع">{{ $row['quantity'] }}</td><td data-label="القيمة ₪">{{ $row['amount_text'] }}</td>
<td data-label="النتيجة" data-opening-result role="status">{{ ['eligible'=>'جاهز للمعاينة','already_posted'=>'مثبت ماليًا؛ لن يكرر','rejected'=>'غير جاهز'][$row['status']] }}@foreach($row['issues'] as $issue)<p>{{ $issue }}</p>@endforeach</td>
</tr>
@empty<tr><td colspan="6">لا توجد مستندات افتتاحية معتمدة ضمن الفروع المسموحة.</td></tr>@endforelse
</tbody></table></div>
<label class="sale-check"><input type="checkbox" name="confirmed" value="1" disabled> راجعت أثر كل مستند في المعاينة وأوافق صراحة على اعتماد المحدد.</label>
<div class="sale-actions"><button type="submit" class="btn btn-outline">معاينة المحدد</button><button type="button" class="btn btn-primary" data-opening-batch-post disabled>اعتماد المحدد</button></div>
<p data-opening-batch-feedback role="status" aria-live="polite"></p>
</fieldset>
</form></section>
