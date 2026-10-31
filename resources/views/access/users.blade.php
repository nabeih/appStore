@extends('layouts.app')
@section('title', 'إدارة الموظفين')
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/access.css') }}">@endpush
@section('content')
<div class="sale-page access-page">
<header class="sale-header"><h1>إدارة الموظفين</h1><a class="btn btn-primary" href="{{ route('access.users.create') }}">إضافة موظف</a>@can('access.roles.manage')<a class="btn btn-outline" href="{{ route('access.roles.index') }}">الأدوار والصلاحيات</a>@endcan</header>
@include('access.feedback')
<p class="sale-notice">أنشئ حساب الموظف ثم اختر دوره وفرعه. يمكن تعديل بياناته أو تعطيله مع الاحتفاظ بسجلاته. لا يمنح اختيار الدور وصولًا إلى فروع أخرى دون صلاحية العمل بجميع الفروع.</p>
@foreach($users as $employee)
<section class="sale-panel"><h2>{{ $employee->name }} <small>{{ $employee->username }}</small></h2>
<p>{{ $employee->role?->display_name ?? 'بلا دور' }} — {{ $employee->branch?->name ?? 'بلا فرع محدد' }} — {{ $employee->is_active ? 'الحساب فعال' : 'الحساب معطل' }}</p>
<details><summary>الصلاحيات الفعلية</summary>
@if($employee->is_active && $employee->role?->is_active)<ul>@foreach($employee->role->permissions as $permission)<li>{{ $permission->display_name }}</li>@endforeach</ul>
@else<p>لا توجد صلاحيات فعالة لهذا الحساب.</p>@endif
</details>
@if($employee->role?->name === 'sales_owner' || $employee->id === auth()->id() || array_diff($employee->role?->permissions->pluck('name')->all() ?? [], $grantable) !== [])
<p>هذا الحساب محمي من التعديل عبر هذه الشاشة.</p>
@else
<p><a class="btn btn-outline" href="{{ route('access.users.edit', $employee) }}">تعديل البيانات أو إعادة تعيين كلمة المرور</a></p>
<form method="post" action="{{ route('access.users.assign', $employee) }}">@csrf @method('PUT')
<div class="sale-grid">
<label>الدور<select name="role_id" required><option value="">اختر الدور</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected($employee->role_id === $role->id)>{{ $role->display_name }}</option>@endforeach</select></label>
<label>الفرع<select name="branch_id"><option value="">بلا فرع — يتطلب دور جميع الفروع</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($employee->branch_id === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label>الوصول<select name="is_active"><option value="1" @selected($employee->is_active)>فعال</option><option value="0" @selected(!$employee->is_active)>معطل</option></select></label>
</div><button class="btn btn-primary" type="submit">حفظ وصول الموظف</button>
</form>
@endif
</section>
@endforeach
<x-pagination :paginator="$users" />
</div>
@endsection
