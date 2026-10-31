@can('catalog.search')@can('inventory.view')
<div class="product-search-tools" data-search-target="{{ $target }}">
    <button type="button" class="btn btn-outline btn-sm" data-scan-mode="barcode">مسح باركود</button>
    <button type="button" class="btn btn-outline btn-sm" data-scan-mode="ocr">قراءة الكود المكتوب</button>
    <button type="button" class="btn btn-outline btn-sm" data-scan-mode="visual" @disabled(!config('product_search.visual_enabled'))>البحث بصورة الملابس{{ config('product_search.visual_enabled') ? '' : ' — غير مفعّل' }}</button>
    @if(config('product_search.visual_enabled'))@can('inventory.manage_catalog')<a class="btn btn-outline btn-sm" href="{{ route('search.visual-index') }}">حالة فهرسة الصور</a>@endcan @endif
</div>
@endcan @endcan
