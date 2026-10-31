@extends('layouts.app')
@section('title', $employee ? 'تعديل حساب الموظف' : 'إضافة موظف')
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/access.css') }}">@endpush
@section('content')
<div class="sale-page access-page">
    <header class="sale-header"><h1>{{ $employee ? 'تعديل حساب الموظف' : 'إضافة موظف' }}</h1><a class="btn btn-outline" href="{{ route('access.users.index') }}">إدارة الموظفين</a></header>
    @include('access.feedback')
    <section class="sale-panel">
        <form method="post" action="{{ $employee ? route('access.users.update', $employee) : route('access.users.store') }}">@csrf @if($employee)@method('PUT')@endif
            <div class="sale-grid">
                <label>اسم الموظف<input name="name" required maxlength="255" autocomplete="name" value="{{ old('name', $employee?->name) }}"></label>
                <label>اسم المستخدم<input name="username" required minlength="3" maxlength="50" autocomplete="off" autocapitalize="none" spellcheck="false" value="{{ old('username', $employee?->username) }}"><small>يستخدم لتسجيل الدخول؛ يقبل العربية والإنجليزية والأرقام.</small></label>
                <label>البريد الإلكتروني — اختياري<input name="email" type="email" maxlength="255" autocomplete="off" value="{{ old('email', $employee?->email) }}"><small>لن يرسل النظام رسائل بريد.</small></label>
                <label>الدور<select name="role_id" required data-employee-role><option value="">اختر الدور</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string) old('role_id', $employee?->role_id) === (string) $role->id)>{{ $role->display_name }}</option>@endforeach</select></label>
                <label>الفرع<select name="branch_id" required><option value="">اختر فرع الموظف</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id', $employee?->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                <label>حالة الحساب<select name="is_active"><option value="1" @selected((string) old('is_active', (int) ($employee?->is_active ?? 1)) === '1')>نشط</option><option value="0" @selected((string) old('is_active', (int) ($employee?->is_active ?? 1)) === '0')>معطل</option></select></label>
                @unless($employee)
                    <label>كلمة المرور الأولية<input type="password" name="password" required minlength="4" maxlength="72" autocomplete="new-password"></label>
                    <label>تأكيد كلمة المرور<input type="password" name="password_confirmation" required minlength="4" maxlength="72" autocomplete="new-password"></label>
                @endunless
            </div>
            @php($selectedRole = $roles->firstWhere('id', (int) old('role_id', $employee?->role_id)))
            <section class="access-role-preview" aria-live="polite"><h2>صلاحيات الدور المختار</h2><p data-employee-role-status>{{ $selectedRole ? $selectedRole->display_name : 'اختر دورًا لعرض صلاحياته.' }}</p><ul data-employee-role-summary>@if($selectedRole)@foreach($selectedRole->permissions as $permission)<li>{{ $permission->display_name }}</li>@endforeach @endif</ul><small>الحساب المعطل بلا صلاحيات فعالة. نطاق الفرع يبقى مفروضًا ما لم يمنح الدور العمل بجميع الفروع.</small></section>
            @can('access.roles.manage')<p>تحتاج صلاحيات مختلفة؟ <a href="{{ route('access.roles.index') }}">إنشاء دور مخصص أو مراجعة الأدوار</a></p>@endcan
            <p class="sale-notice">{{ $employee ? 'تعديل بيانات الحساب لا يغير كلمة المرور. استخدم النموذج المنفصل أدناه عند الحاجة.' : 'اختر كلمة مرور يصعب تخمينها وسلّمها للموظف مع اسم المستخدم عبر وسيلة خاصة. لن تظهر بعد حفظ الحساب.' }}</p>
            <button class="btn btn-primary" type="submit">{{ $employee ? 'حفظ بيانات الموظف' : 'إنشاء حساب الموظف' }}</button>
        </form>
    </section>
    @if($employee)
    <section class="sale-panel"><h2>إعادة تعيين كلمة المرور</h2><p>لا يمكن عرض كلمة المرور القديمة. التعيين الجديد يبطل الجلسات القديمة في الطلب التالي، ولا يفعّل حسابًا معطلًا.</p>
        <form method="post" action="{{ route('access.users.password', $employee) }}">@csrf @method('PUT')
            <div class="sale-grid"><label>كلمة المرور الجديدة<input type="password" name="password" required minlength="4" maxlength="72" autocomplete="new-password"></label><label>تأكيد كلمة المرور الجديدة<input type="password" name="password_confirmation" required minlength="4" maxlength="72" autocomplete="new-password"></label></div>
            <button class="btn btn-outline" type="submit">تعيين كلمة المرور الجديدة</button>
        </form>
    </section>
    @endif
</div>
<script type="application/json" id="employee-role-definitions">{!! \Illuminate\Support\Js::encode($roles->mapWithKeys(fn ($role) => [(string) $role->id => ['name' => $role->display_name, 'permissions' => $role->permissions->pluck('display_name')->all()]])) !!}</script>
@endsection
@push('scripts')<script src="{{ asset('assets/admin/js/pages/access/employees.js') }}" defer></script>@endpush
