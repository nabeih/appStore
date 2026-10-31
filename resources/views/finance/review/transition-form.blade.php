<section class="sale-panel" data-legacy-transition data-preview-url="{{ route('finance.transition.preview') }}" data-post-url="{{ route('finance.transition.post') }}" data-enabled="{{ config('finance.legacy_transition_enabled') ? '1' : '0' }}">
<h2>معاينة الانتقال المالي التاريخي</h2>
<p class="sale-notice">المعاينة لا تغيّر الأرصدة. الاعتماد يرحّل مستندًا واحدًا ماليًا فقط، دون إضافة بضاعة أو دفعة. مسار الأرصدة المجمعة غير مفعّل.</p>
@unless(config('finance.legacy_transition_enabled'))<p>الاعتماد غير مفعّل من مسؤول النظام؛ المعاينة وأسباب الرفض متاحة.</p>@endunless
<form data-transition-form><div class="sale-grid">
<label>الفرع<select name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
<label>المستند<select name="source" required><option value="">اختر المستند</option>@foreach($transition['documents'] as $document)<option value="{{ $document['kind'] }}:{{ $document['id'] }}">{{ $document['label'] }} — {{ $document['date'] }} — {{ $document['posted'] ? 'قيد موجود: لن يكرر' : 'يلزم معاينة وأدلة' }}</option>@endforeach</select></label>
<label>تاريخ القيد المثبت<input type="date" name="posting_date" required></label>
<label>مرجع الدليل<textarea name="evidence" minlength="5" maxlength="2000" required></textarea></label>
<label>سبب الانتقال<input name="reason" minlength="3" maxlength="1000" required></label>
</div>
<details><summary>إثبات حساب وتمويل سداد المورد</summary>
<label>الحساب الفعلي<select name="account_id"><option value="">غير محدد — لا يستنتج من طريقة الدفع</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }} — فرع #{{ $account->branch_id }}</option>@endforeach</select></label>
<label>شمول الدفعة في رصيد آخر<select name="opening_inclusion"><option value="unknown">غير معروف</option><option value="excluded">أثبت أنها لم تخصم ضمن رصيد انتقال آخر</option><option value="included">شملها رصيد آخر — لا تكررها</option></select></label>
<label>دليل مصدر الأموال ورصيد الحساب<textarea name="funding_evidence" maxlength="2000"></textarea></label>
<label class="sale-check"><input type="checkbox" name="funding_confirmed" value="1"> راجعت الحساب والتاريخ وشمول الأرصدة وأؤكد التمويل.</label>
</details>
<label class="sale-check"><input type="checkbox" name="confirmed" value="1"> أوافق صراحة على الأثر بعد معاينته.</label>
<div class="sale-actions"><button type="submit" class="btn btn-outline">معاينة فقط</button><button type="button" class="btn btn-primary" data-transition-post disabled>اعتماد الأثر المعروض</button></div>
<div role="status" aria-live="polite" data-transition-feedback></div>
<pre data-transition-result style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>
</form></section>
@can('finance.reverse')
<section class="sale-panel"><h2>اعتمادات الانتقال والعكس الموثق</h2>
@forelse($transitionAudits as $audit)
<details><summary>قيد #{{ $audit->data['journal_id'] }} — {{ $audit->created_at }} — مستخدم #{{ $audit->user_id }}</summary><p>{{ $audit->reason }}</p>
@if(config('finance.legacy_transition_enabled'))
<form action="{{ route('finance.transition.reverse', $audit->data['journal_id']) }}" data-simple-form>@include('sales.partials.feedback')
<fieldset><label>سبب العكس<input name="reason" required minlength="5" maxlength="1000"></label><p>عكس مالي فقط. يرفض مع قيود لاحقة تابعة أو بعد إنهاء الإعداد؛ يبقى سجل التدقيق محفوظًا.</p><button class="btn btn-outline">عكس اعتماد الانتقال</button></fieldset></form>
@endif</details>
@empty<p>لا اعتمادات انتقال مسجلة بهذه الآلية.</p>@endforelse
</section>@endcan
@push('scripts')<script src="{{ asset('assets/admin/js/pages/sales/legacy-transition.js') }}" defer></script>@endpush
