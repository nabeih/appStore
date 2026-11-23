@extends('layouts.app')
@section('title','إضافة مديونية مورد سابقة')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/purchases/invoices.css') }}">@endpush
@section('content')
<div class="trade-page" dir="rtl">
    <header class="trade-heading"><div><a href="{{ route('suppliers.show',$supplier) }}">كشف المورد</a><h1>إضافة مديونية سابقة: {{ $supplier->name }}</h1></div></header>
    <p class="trade-muted">هذا المستند لالتزام كان قائمًا قبل بدء التتبع ولم يُسجل. لا يُنشئ فاتورة شراء أو مخزونًا، ولا يفتح فترة مغلقة. الالتزامات الجديدة تُسجل بمستندها الصحيح.</p>
    <form method="post" action="{{ route('suppliers.debts.store',$supplier) }}" enctype="multipart/form-data" class="card trade-panel">
        @if($errors->any())<div class="validation-summary" data-validation-summary role="alert"><strong>لم تُحفظ المديونية. صحّح البيانات التالية:</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul><small>إذا رغبت بإرفاق مستند، أعد اختياره عند المحاولة مجددًا.</small></div>@endif
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
        <label>الفرع<select @error('branch_id') aria-describedby="field-error-branch_id" aria-invalid="true" class="validation-invalid" @enderror name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)old('branch_id')===$branch->id)>{{ $branch->name }} — بداية التتبع المسجلة لا تتجاوز {{ $cutoffs[$branch->id] }}</option>@endforeach</select></label><x-field-error name="branch_id" />
        <label>المبلغ المستحق غير المسجل ₪<input @error('amount') aria-describedby="field-error-amount" aria-invalid="true" class="validation-invalid" @enderror name="amount" inputmode="decimal" value="{{ old('amount') }}" required></label><x-field-error name="amount" />
        <label>تاريخ نشوء الدين الحقيقي<input @error('original_date') aria-invalid="true" class="validation-invalid" @enderror type="date" name="original_date" aria-describedby="debt-origin-help @error('original_date') field-error-original_date @enderror" value="{{ old('original_date') }}" required></label><x-field-error name="original_date" />
        <small id="debt-origin-help">متى أصبح المبلغ مستحقًا للمورد فعلًا؟ يجب أن يسبق بداية التتبع، حتى لو سجلته اليوم.</small>
        <label>تاريخ بدء تتبع التزامات الموردين<input @error('tracking_started_on') aria-invalid="true" class="validation-invalid" @enderror type="date" name="tracking_started_on" aria-describedby="debt-tracking-help @error('tracking_started_on') field-error-tracking_started_on @enderror" value="{{ old('tracking_started_on', reset($cutoffs) ?: '') }}" required></label><x-field-error name="tracking_started_on" />
        <small id="debt-tracking-help">الدين يجب أن يسبق هذا التاريخ. لا يجوز جعل بداية التتبع متأخرة عن أول دليل مسجل في النظام.</small>
        <label>تاريخ القيد المحاسبي ضمن فترة مفتوحة<input @error('posting_date') aria-invalid="true" class="validation-invalid" @enderror type="date" name="posting_date" aria-describedby="debt-posting-help @error('posting_date') field-error-posting_date @enderror" value="{{ old('posting_date', \App\Support\Sales\OperationContext::today()) }}" required></label><x-field-error name="posting_date" />
        <small id="debt-posting-help">متى تسجل أثر الدين في الحسابات الآن؟ يمكن تسجيل دين قديم بعد بدء التشغيل، بتاريخ ضمن فترة مفتوحة، دون تغيير تاريخ نشوئه.</small>
        <label>مرجع كشف المورد / الدليل<input @error('reference') aria-describedby="field-error-reference" aria-invalid="true" class="validation-invalid" @enderror name="reference" maxlength="150" value="{{ old('reference') }}" required></label><x-field-error name="reference" />
        <label>سبب عدم التسجيل وكيف تأكدت أنه التزام افتتاحي<textarea @error('notes') aria-describedby="field-error-notes" aria-invalid="true" class="validation-invalid" @enderror name="notes" minlength="10" maxlength="2000" required>{{ old('notes') }}</textarea></label><x-field-error name="notes" />
        <label>المستند الداعم — اختياري (PDF أو صورة، حتى 10MB)<input @error('evidence') aria-describedby="field-error-evidence" aria-invalid="true" class="validation-invalid" @enderror type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp"></label><x-field-error name="evidence" />
        <p class="trade-muted">يمكن المتابعة دون مرفق. إن أُرفق مستند، سيُحفظ بصورة خاصة. بيانات الدين والمرجع والمراجعة والاعتماد الصريح تبقى إلزامية.</p>
        <button class="btn btn-primary" type="submit">حفظ المسودة ومراجعة الأثر</button>
    </form>
</div>
@endsection
