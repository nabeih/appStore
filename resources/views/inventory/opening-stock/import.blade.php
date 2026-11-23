@extends('layouts.app')
@section('title', 'استيراد البضاعة الافتتاحية من Excel')
@include('sales.partials.assets')
@push('scripts')<script src="{{ asset('assets/admin/js/pages/inventory/opening-import.js') }}" defer></script>@endpush
@section('content')
<div class="sale-page" dir="rtl">
    <header class="sale-header"><h1>استيراد البضاعة الافتتاحية من Excel</h1><a href="{{ route('inventory.opening-stock.index') }}">سجل البضاعة الافتتاحية</a></header>
    <section class="sale-panel">
        <h2>1. جهّز الملف</h2>
        <a class="btn btn-outline" href="{{ route('inventory.opening-stock.import.template') }}">تنزيل قالب Excel</a>
        <p>كل سطر تركيبة واحدة من منتج ولون ومقاس. أدخل اللون والمقاس «بدون» إذا لم ينطبقا. التصنيفات والألوان والمقاسات الجديدة تُنشأ عند حفظ المسودة فقط؛ لا تغيّر الأكواد القديمة.</p>
        <p>الأعمدة بالترتيب: اسم المنتج product_name، التصنيف category، اللون color، المقاس size، الكمية opening_quantity، تكلفة الوحدة unit_cost، سعر البيع selling_price. اختياري: كود المنتج product_code، وإقرار تكلفة صفر zero_cost_confirmed=1. لا تترك التكلفة فارغة. SKU يُولّد من أكواد المنتج واللون والمقاس؛ لا يوجد استيراد مستقل لباركود أو علامة تجارية في المسار الحالي.</p>
        <p>للبيانات القديمة: صدّر القيم إلى Excel ثم طابق الأعمدة مع القالب وراجع النتائج. لا ترفع ملفات SQL؛ لا ينفذ التطبيق SQL مستوردًا. الصيغ والروابط الخارجية والماكرو مرفوضة.</p>
        <p>الحد 5000 سطر و4MB. تُقسّم البيانات إلى مستندات لا تتجاوز 300 سطر. تكلفة الصفر تُقر صراحةً، وقد تحتاج توثيقًا إضافيًا من شاشة الجاهزية وفق قواعد التشغيل الحالية.</p>
    </section>
    <section class="sale-panel">
        <h2>2. معاينة ثم حفظ مسودات</h2>
        <form id="opening-import-form" data-preview-url="{{ route('inventory.opening-stock.import.preview') }}" data-store-url="{{ route('inventory.opening-stock.import.store') }}">
            @csrf
            <fieldset><div class="sale-grid">
                <label>الفرع<select name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <label>تاريخ الافتتاح<input name="opening_date" type="date" value="{{ now()->toDateString() }}" required></label>
                <label>العملة<select name="currency"><option value="ILS">شيكل ILS — العملة المحاسبية الحالية</option></select></label>
                <label>الملف<input name="file" type="file" accept=".xlsx" required></label>
            </div><button type="submit" class="btn btn-primary">معاينة الملف دون حفظ</button></fieldset>
            <p data-import-error role="alert" hidden></p>
            <div data-import-preview hidden>
                <p data-import-totals></p>
                <p>تعرض المعاينة أول 100 سطر؛ يُتحقق من جميع الأسطر قبل الحفظ. اعتماد البضاعة لاحقًا يستخدم مسار الاعتماد الرسمي نفسه.</p>
                <div class="table-responsive"><table class="sale-table"><thead><tr><th>المنتج</th><th>التصنيف</th><th>اللون</th><th>المقاس</th><th>الكمية</th><th>التكلفة</th><th>سعر البيع</th></tr></thead><tbody data-import-rows></tbody></table></div>
                <label class="sale-check"><input type="checkbox" name="confirmed" value="1"> راجعت الملف كاملًا، وأؤكد أن هذه أرصدة أول المدة وليست مشتريات جديدة أو بضاعة مدخلة سابقًا.</label>
                <button type="button" class="btn btn-primary" data-import-save>حفظ مسودات المحدد</button>
            </div>
            <div data-import-result role="status"></div>
        </form>
        @if($branches->isEmpty())<p role="alert">لا يوجد فرع مفتوح للإدخال ضمن صلاحياتك. بعد الإغلاق استخدم المشتريات.</p>@endif
    </section>
</div>
@endsection
