@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/access.css') }}">@endpush
@section('content')
<div class="sale-page access-page">
<header class="sale-header"><h1>الأدوار والصلاحيات</h1>@can('access.users.manage')<a class="btn btn-outline" href="{{ route('access.users.index') }}">إدارة الموظفين</a>@endcan</header>
@include('access.feedback')
<p class="sale-notice">لكل موظف دور واحد. دور المالك محمي. تعديل الدور يسري على جميع الموظفين المرتبطين به. الفروع مستقلة عن صلاحيات العمليات.</p>
<section class="sale-panel"><h2>الأدوار الحالية</h2><div class="access-role-list">
@foreach($roles as $role)
<article><strong>{{ $role->display_name }}</strong><small>{{ $role->is_active ? 'فعال' : 'معطل' }} — {{ $role->users_count }} حساب</small>
<details><summary>عرض الصلاحيات</summary><ul>@foreach($role->permissions as $permission)<li>{{ $permission->display_name }}</li>@endforeach</ul></details>
@if($role->name === 'sales_owner')<span>دور المالك محمي</span>
@elseif((int) auth()->user()->role_id === $role->id)<span>الدور المسند إليك محمي من تعديلك</span>
@elseif(array_diff($role->permissions->pluck('name')->all(), $grantable) === [])<a href="{{ route('access.roles.edit', $role) }}">تعديل الصلاحيات والحالة</a>@endif
</article>
@endforeach
</div></section>
@if(!$selected || ($selected->name !== 'sales_owner' && (int) auth()->user()->role_id !== $selected->id && array_diff($selected->permissions->pluck('name')->all(), $grantable) === []))
<section class="sale-panel"><h2>{{ $selected ? 'تعديل الدور: '.$selected->display_name : 'إنشاء دور مخصص' }}</h2>
<form method="post" action="{{ $selected ? route('access.roles.update', $selected) : route('access.roles.store') }}">@csrf @if($selected)@method('PUT')@endif
<div class="sale-grid"><label>اسم الدور<input name="display_name" maxlength="150" required value="{{ old('display_name', $selected?->display_name) }}"></label>
<label>الحالة<select name="is_active"><option value="1" @selected((string) old('is_active', (int) ($selected?->is_active ?? 1)) === '1')>فعال</option><option value="0" @selected((string) old('is_active', (int) ($selected?->is_active ?? 1)) === '0')>معطل</option></select></label></div>
@foreach($groups as $group => $permissions)<fieldset class="access-permissions"><legend>{{ $group }}</legend>
@foreach($permissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked(in_array($permission->name, old('permissions', $selected?->permissions->pluck('name')->all() ?? [])))><span>{{ $permission->display_name }}<small>{{ $permission->name }}</small></span></label>@endforeach
</fieldset>@endforeach
<button class="btn btn-primary" type="submit">حفظ الدور</button>@if($selected)<a class="btn btn-outline" href="{{ route('access.roles.index') }}">إنشاء دور آخر</a>@endif
</form></section>
@endif
</div>
@endsection
