@if(config('product_search.visual_enabled'))@can('catalog.search')@can('inventory.view')
<dialog id="visual-product-search" class="product-scanner" data-url="{{ route('search.products.visual') }}">
    <header><h2>البحث بصورة الملابس</h2><button type="button" data-visual-close aria-label="إغلاق">×</button></header>
    <div class="product-scanner-body">
        <p>صوّر القطعة كاملة بإضاءة واضحة. ستُرسل الصورة إلى التطبيق المحلي فقط بعد تأكيدك، دون حفظها دائمًا.</p>
        <label>الفرع<select data-visual-branch>@foreach(\App\Support\Sales\SalesReports::branches(auth()->user(), false) as $visualBranch)<option value="{{ $visualBranch->id }}">{{ $visualBranch->name }}</option>@endforeach</select></label>
        <p role="status" data-visual-status></p>
        <video data-visual-video autoplay muted playsinline hidden></video>
        <div data-visual-input><div class="product-search-tools">
            <button type="button" class="btn btn-outline" data-visual-camera>تشغيل الكاميرا الخلفية</button>
            <button type="button" class="btn btn-outline" data-visual-capture hidden>التقاط صورة</button>
            <label class="btn btn-outline">اختيار صورة<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" data-visual-file></label>
        </div>
        <img data-visual-preview hidden alt="معاينة الصورة قبل إرسالها" style="max-width:100%;max-height:240px;object-fit:contain">
        <button type="button" class="btn btn-primary" data-visual-submit disabled>تأكيد الصورة والبحث</button>
        </div><button type="button" class="btn btn-outline" data-visual-new hidden>البحث بصورة أخرى</button>
        <p>النتائج تشابه محتمل وليست ضمانًا للتطابق. تظهر المنتجات المفهرسة فقط؛ الصور الجديدة قد تكون قيد التجهيز.</p>
        <section data-visual-results aria-live="polite"></section>
    </div>
</dialog>
@endcan @endcan @endif
