@extends('layouts.app')
@section('title','إعداد '.$title)
@section('content')
<div class="screen active commerce-page"><header class="commerce-header"><div><a href="{{ route('store.admin.content.index',$type) }}">إدارة الموقع / {{ $title }}</a><h1>{{ $entry->exists?'تعديل':'إضافة' }} {{ $title }}</h1></div><a class="btn btn-outline" href="{{ route('store.admin.content.index',$type) }}">إلغاء</a></header>
@include('store.admin.feedback')
<form class="commerce-card commerce-form content-form" method="POST" enctype="multipart/form-data" action="{{ $entry->exists?route('store.admin.content.update',[$type,$entry->id]):route('store.admin.content.store',$type) }}">@csrf @if($entry->exists)@method('PUT')@endif
@if($type==='products')
<label>المنتج من المخزون<select name="product_id" required>@foreach($products as $product)<option value="{{ $product->id }}" @selected((int)old('product_id',$entry->product_id)===$product->id)>{{ $product->name }} — {{ $product->code }}</option>@endforeach</select></label>
<label>شارة العرض<input name="badge" maxlength="80" value="{{ old('badge',$entry->badge) }}" placeholder="نص اختياري، مثل تشكيلة جديدة"></label>
<label class="commerce-check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published',$entry->is_published))> منشور على الموقع</label>
<p class="commerce-muted">الصور والألوان والمقاسات والأسعار الأساسية من المنتج نفسه. تعديلها من صفحة المنتج في المخزون.</p>
@elseif($type==='banners')
<label>عنوان البانر<input name="title" required maxlength="255" value="{{ old('title',$entry->title) }}"></label>
<label>صورة البانر<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
@if($entry->image_path)<img class="commerce-banner-preview" src="{{ Storage::disk('public')->url($entry->image_path) }}" alt="{{ $entry->title }}">@endif
@else
<label>الاسم<input name="name" required maxlength="255" value="{{ old('name',$entry->name) }}"></label>
@endif
@if(in_array($type,['products','sections']))
<label>الرابط الثابت<input name="slug" required maxlength="150" value="{{ old('slug',$entry->slug) }}" @readonly($entry->exists) placeholder="مثال: ملابس-رجالية"><small>فريد وثابت بعد الحفظ للحفاظ على الروابط.</small></label>
@endif
@if(in_array($type,['products','banners']))
<label>القسم<select name="store_section_id"><option value="">دون قسم</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected((int)old('store_section_id',$entry->store_section_id)===$section->id)>{{ $section->name }}</option>@endforeach</select></label>
@endif
@if($type==='sections')
<label>التصنيف المرتبط<select name="category_id"><option value="">دون تصنيف</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int)old('category_id',$entry->category_id)===$category->id)>{{ $category->name }}</option>@endforeach</select></label>
@endif
@if(in_array($type,['products','sections','banners']))
<label>الوصف<textarea name="description" maxlength="10000" rows="5">{{ old('description',$entry->description) }}</textarea></label><label>ترتيب الظهور<input name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order',$entry->sort_order??0) }}"></label>
@endif
@if($type==='zones')
@foreach(['fee'=>'رسوم الزبون الأصلية','courier_delivery'=>'أجرة مزوّد الخدمة عند التسليم','courier_refusal'=>'أجرة المزوّد عند رفض الاستلام'] as $field=>$label)
<label>{{ $label }}<input name="{{ $field }}" inputmode="decimal" @required($field==='fee') pattern="[0-9]+([.][0-9]{1,2})?" value="{{ old($field,$entry->{$field.'_minor'}===null?'':\App\Support\Sales\Money::format($entry->{$field.'_minor'})) }}"></label>@endforeach
<p class="commerce-notice">اترك أجرة المزوّد فارغة إن كانت مجهولة؛ لن يُسمح بإخراج الطلب حتى تحديدها. أدخل 0.00 فقط إذا كان الصفر معلومًا ومتفقًا عليه. رفض الاستلام يستحق رسوم المنطقة الأصلية ولو كان التوصيل مجانيًا.</p>
@endif
@if($type==='offers')
<label>نوع العرض<select name="type"><option value="product" @selected(old('type',$entry->type)==='product')>خصم منتجات الموقع</option><option value="delivery" @selected(old('type',$entry->type)==='delivery')>توصيل مجاني</option></select></label>
<label>خصم المنتج بالنسبة المئوية (0 لعرض التوصيل)<input name="discount_percent" required inputmode="decimal" value="{{ old('discount_percent',\App\Support\Sales\Money::format($entry->percent_basis_points??0)) }}"></label>
<label>حد صافي مشتريات عرض التوصيل<input name="minimum" required inputmode="decimal" value="{{ old('minimum',\App\Support\Sales\Money::format($entry->minimum_minor??0)) }}"></label>
<label class="commerce-check"><input type="checkbox" name="minimum_inclusive" value="1" @checked(old('minimum_inclusive',$entry->exists?$entry->minimum_inclusive:true))> الحد شامل: المبلغ يساوي الحد أو أكبر منه (دون التحديد: أكبر منه فقط)</label>
<label>المناطق المشمولة<select name="zone_ids[]" multiple size="4">@foreach($zones as $zone)<option value="{{ $zone->id }}" @selected(in_array($zone->id,old('zone_ids',$entry->zone_ids??[])))>{{ $zone->name }}</option>@endforeach</select><small>دون اختيار = جميع المناطق.</small></label>
<label>المنتجات المشمولة بالخصم<select name="product_ids[]" multiple size="6">@foreach($products as $product)<option value="{{ $product->id }}" @selected(in_array($product->id,old('product_ids',$entry->product_ids??[])))>{{ $product->name }}</option>@endforeach</select><small>دون اختيار = جميع المنتجات المنشورة.</small></label>
<label>يبدأ في<input type="datetime-local" name="starts_at" value="{{ old('starts_at',$entry->starts_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i')) }}"></label>
<label>ينتهي في<input type="datetime-local" name="ends_at" value="{{ old('ends_at',$entry->ends_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i')) }}"></label><p class="commerce-muted">التوقيت: {{ config('app.timezone') }}. عند اجتماع عروض المنتجات يُطبق أفضل سعر واحد، دون تكديس الخصومات. حد التوصيل من الصافي بعد الخصم، دون الرسوم.</p>
@endif
@if($type==='settings')
<label>فرع تنفيذ طلبات الموقع<select name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)old('branch_id',$entry->branch_id)===$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label>هاتف التواصل<input name="phone" type="tel" maxlength="40" value="{{ old('phone',$entry->phone) }}"></label>
<label>سياسة الطلب والتوصيل ورفض الاستلام<textarea name="policy" required minlength="20" maxlength="15000" rows="8">{{ old('policy',$entry->policy??\App\Actions\Store\StoreContext::POLICY_TEMPLATE) }}</textarea></label><p class="commerce-notice">اكتب سياسة واضحة تتضمن موافقة الزبون على دفع رسوم المنطقة الأصلية عند رفض الاستلام، حتى مع عرض التوصيل المجاني. تُحفظ نسخة السياسة وموافقة الزبون مع كل طلب.</p>
<label class="commerce-check"><input type="checkbox" name="is_open" value="1" @checked(old('is_open',$entry->is_open))> فتح المتجر العام لقبول الطلبات</label>
@endif
@if(in_array($type,['settings','products','sections']))
<label>عنوان محركات البحث والمشاركة<input name="seo_title" maxlength="255" value="{{ old('seo_title',$entry->seo_title) }}"></label><label>وصف محركات البحث والمشاركة<textarea name="seo_description" maxlength="1000">{{ old('seo_description',$entry->seo_description) }}</textarea></label>
@endif
@if(!in_array($type,['settings','products']))<label class="commerce-check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$entry->exists?$entry->is_active:true))> فعال</label>@endif
<div class="commerce-actions"><button class="btn btn-primary">حفظ التغييرات</button><a class="btn btn-outline" href="{{ route('store.admin.content.index',$type) }}">إلغاء</a></div>
</form></div>
@endsection