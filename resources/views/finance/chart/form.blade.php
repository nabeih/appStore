@if($errors->any())<div class="sale-errors" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ $entry?route('finance.chart.update',$entry):route('finance.chart.store') }}">
@csrf @if($entry)@method('PUT')@endif
<div class="sale-grid">
<label>اسم الحساب<input name="name" value="{{ old('name',$entry?->name) }}" maxlength="150" required></label>
@if($entry?->is_protected)
@foreach(['code','parent_id','branch_id','account_group','normal_balance','is_posting','is_active'] as $field)<input type="hidden" name="{{ $field }}" value="{{ is_bool($entry->$field)?(int)$entry->$field:$entry->$field }}">@endforeach
<p class="sale-notice">هذا حساب نظام محمي؛ يمكنك تحسين اسمه فقط مع توثيق السبب.</p>
@else
<label>رمز الحساب<input name="code" value="{{ old('code',$entry?->code) }}" maxlength="80" pattern="[A-Za-z0-9_-]+" required dir="ltr"></label>
<label>المجموعة<select name="account_group" required>@foreach(\App\Actions\Finance\ChartOfAccounts::GROUPS as $key=>$label)<option value="{{ $key }}" @selected(old('account_group',$entry?->account_group)===$key)>{{ $label }}</option>@endforeach</select></label>
<label>طبيعة الرصيد<select name="normal_balance"><option value="debit" @selected(old('normal_balance',$entry?->normal_balance)==='debit')>مدين</option><option value="credit" @selected(old('normal_balance',$entry?->normal_balance)==='credit')>دائن</option></select></label>
<label>الحساب الأب<select name="parent_id"><option value="">حساب رئيسي ضمن المجموعة</option>@foreach($accounts->where('is_posting',false)->where('is_active',true) as $parent)@if($parent->id!==$entry?->id)<option value="{{ $parent->id }}" @selected((int)old('parent_id',$entry?->parent_id)===$parent->id)>{{ $parent->code }} — {{ $parent->name }}</option>@endif @endforeach</select></label>
<label>نطاق الفرع<select name="branch_id">@can('sales.all_branches')<option value="">عام لجميع الفروع</option>@endcan @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)old('branch_id',$entry?->branch_id)===$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label>إمكانية الترحيل<select name="is_posting"><option value="1" @selected((int)old('is_posting',$entry?->is_posting??1)===1)>تفصيلي — قابل للترحيل</option><option value="0" @selected((int)old('is_posting',$entry?->is_posting??1)===0)>تجميعي — دون قيود مباشرة</option></select></label>
<label>الحالة<select name="is_active"><option value="1" @selected((int)old('is_active',$entry?->is_active??1)===1)>فعال</option><option value="0" @selected((int)old('is_active',$entry?->is_active??1)===0)>معطل</option></select></label>
@endif
<label class="sale-wide">سبب الإضافة أو التعديل<input name="reason" required minlength="3" maxlength="500" value="{{ old('reason') }}"></label>
</div><button class="btn btn-primary" type="submit">{{ $entry?'حفظ التعديل':'إنشاء الحساب' }}</button>
</form>
