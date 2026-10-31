<dialog id="product-details-dialog" class="sale-dialog" aria-labelledby="product-details-heading">
<p class="sale-notice" data-product-variant-warning>حفظ بيانات التركيبة قد يعيد حساب SKU وفق كود المنتج واللون والمقاس بالآلية الحالية، وقد يبطل الملصقات المطبوعة؛ راجع الكود قبل إعادة الطباعة.</p>
<form id="product-details-form">
    <div class="sale-header"><h2 id="product-details-heading">بيانات المنتج الحالية</h2><button type="button" class="btn btn-outline" data-close-product-details>إغلاق</button></div>
    <p class="sale-muted" data-product-editor-hint>يتغير الاسم والوصف للمنتج، وسعر البيع للمقاس واللون المحددين. الصورة مشتركة لكل مقاسات اللون نفسه. الفواتير السابقة تحتفظ ببياناتها الأصلية.</p>
    <p id="product-details-feedback" role="alert" hidden></p>
    <fieldset>
        <label>اسم المنتج<input name="name" required maxlength="255"></label>
        <label>الوصف<textarea name="description" rows="3" maxlength="5000"></textarea></label>
        <div data-product-variant-fields><label>سعر البيع الحالي لهذا الصنف ₪<input name="selling_price" type="text" inputmode="decimal" required></label>
        <label>تغيير صورة هذا اللون (اختياري)<input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label>
        <button id="product-fabric-swatch" type="button" class="btn btn-outline">إدارة عينة قماش هذا اللون</button>
        </div>
        <button type="submit" class="btn btn-primary">حفظ بيانات المنتج</button>
    </fieldset>
</form>
</dialog>
@push('scripts')
<script src="{{ asset('assets/admin/js/pages/inventory/product-details.js') }}" defer></script>
@endpush
