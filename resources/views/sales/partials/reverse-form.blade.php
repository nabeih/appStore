@can('sales.reverse')
<details class="sale-danger-panel"><summary>عكس العملية بإجراء موثّق</summary>
<form action="{{ $reverseUrl }}" data-simple-form>
    <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    @include('sales.partials.feedback')
    <fieldset><label>سبب العكس<textarea name="reason" required minlength="3" maxlength="1000" rows="2"></textarea></label>
    <label class="sale-check"><input type="checkbox" name="goods_confirmed" value="1"> {{ $goodsLabel ?? 'أؤكد رجوع البضاعة فعليًا وإمكانية إعادتها للبيع.' }}</label>
    <label class="sale-check"><input type="checkbox" name="funds_confirmed" value="1" required> أؤكد تنفيذ رد أو استرداد الأموال الفعلية المطلوبة للعكس.</label>
    @if($allowRefund ?? false)
        <p class="sale-muted">إذا كان هناك مبلغ مقبوض، حدد حساب رد الأموال الفعلي. للفاتورة ذات المستندات التابعة استخدم مسارها المناسب أولًا.</p>
        <div data-refund-rows><div class="sale-payment-row" data-payment-row>
            <label>حساب رد الأموال<select data-payment-account><option value="">لا يوجد رد نقدي</option>@foreach($accounts as $account)<option value="{{ $account->id }}" data-type="{{ $account->type }}">{{ $account->name }}</option>@endforeach</select></label>
            <label>مبلغ الرد<input type="text" inputmode="decimal" data-payment-amount placeholder="كامل المستحق للعميل"></label>
            <label class="sale-check"><input type="checkbox" data-payment-confirmed> تأكدت من تنفيذ التحويل إن كان الحساب بنكًا أو محفظة.</label>
        </div></div>
    @endif
    <button type="submit" class="btn sale-danger-button">تنفيذ العكس الموثّق</button></fieldset>
</form></details>
@endcan
