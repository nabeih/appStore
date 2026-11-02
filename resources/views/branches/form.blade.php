@extends('layouts.app')
@section('title', $branch ? 'تعديل الفرع' : 'إضافة فرع جديد')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/branches.css') }}">@endpush
@push('scripts')<script src="{{ asset('assets/admin/js/pages/branches.js') }}" defer></script>@endpush
@section('content')
<section class="branches-page">
    <p class="b-breadcrumb"><a href="{{ route('branches.index') }}">إدارة الفروع</a> / {{ $branch ? 'تعديل الفرع' : 'إضافة فرع جديد' }}</p>
    <h1>{{ $branch ? 'تعديل الفرع' : 'إضافة فرع جديد' }}</h1>
    @include('branches.partials.messages')
    <form class="b-panel b-form" method="POST" action="{{ $branch ? route('branches.update', $branch) : route('branches.store') }}" data-branch-save>
        @csrf @if($branch) @method('PUT') @endif
        <label for="branch-name">اسم الفرع <span aria-hidden="true">*</span></label>
        <input id="branch-name" name="name" value="{{ old('name', $branch?->name) }}" maxlength="100" required autocomplete="organization" @error('name') aria-invalid="true" @enderror>
        <label for="branch-address">العنوان <small>(اختياري)</small></label><input id="branch-address" name="address" value="{{ old('address', $branch?->address) }}" maxlength="255" autocomplete="street-address">
        <label for="branch-phone">رقم الهاتف <small>(اختياري)</small></label><input id="branch-phone" name="phone" type="tel" value="{{ old('phone', $branch?->phone) }}" maxlength="50" autocomplete="tel" dir="ltr">
        @if($branch)<p>النوع: {{ $branch->type === 'shop' ? 'متجر' : 'مستودع' }} · الحالة: {{ $branch->is_active ? 'نشط' : 'غير نشط' }}</p>@endif
        <p class="b-note">لا تُغيَّر صلاحيات المستخدمين أو ارتباطاتهم أو جاهزية المخزون والمالية من هذا النموذج.</p>
        <div class="b-actions"><button type="submit" class="b-button">حفظ الفرع</button><a class="b-secondary" href="{{ route('branches.index') }}">إلغاء</a></div>
    </form>
    @if($branch)
    @can('branches.manage_status')
    <section class="b-panel"><h2>إدارة حالة الفرع</h2>
        @if($blockers ?? [])<div class="b-message b-warning"><strong>تعطيل الفرع محظور حاليًا:</strong><ul>@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>@endif
        <p class="b-note">التعطيل متاح للفرع الخالي من التبعيات فقط. الفرع الذي له حركة تشغيلية أو تاريخ مالي يحتاج سياسة إقفال معتمدة قبل تعطيله.</p>
        @include('branches.partials.status', ['branch' => $branch])
    </section>
    @endcan
    @endif
</section>
@endsection
