<dialog id="inventorySetupDialog" class="inventory-setup-dialog" aria-labelledby="inventorySetupTitle">
    <form id="inventorySetupForm">
        <header><h2 id="inventorySetupTitle">تصحيح بيانات المخزون</h2><button type="button" class="btn btn-outline btn-sm" data-setup-close aria-label="إغلاق">✕</button></header>
        <div class="inventory-setup-body">
            <p id="inventorySetupProduct" class="inventory-setup-product"></p>
            <p id="inventorySetupScope" class="inventory-setup-hint"></p>
            <div id="inventorySetupErrors" class="inventory-setup-errors" role="alert" tabindex="-1" hidden></div>
            <fieldset id="inventorySetupFields">
                <div id="inventorySetupEditFields"><p class="inventory-setup-hint">تنبيه الملصقات: حفظ بيانات التركيبة قد يعيد حساب SKU وفق كود المنتج واللون والمقاس؛ ستحتاج الملصقات السابقة إلى إعادة طباعة إذا تغير. لا تُغيّر الأكواد لمجرد الطباعة.</p>
                    <div class="inventory-setup-product-fields">
                        <label>اسم المنتج <input id="inventorySetupName" maxlength="200" required></label>
                        <label>كود المنتج <input id="inventorySetupCode" maxlength="50" dir="ltr" required></label>
                        <label>التصنيف <select id="inventorySetupCategory" required></select></label>
                        <label>اللون <select id="inventorySetupColor" required></select></label>
                        <label>المقاس <select id="inventorySetupSize" required></select></label>
                    </div>
                    <div class="inventory-setup-fields">
                    <label>الكمية المتاحة <input id="inventorySetupQuantity" type="number" min="0" max="1000000" step="1" required></label>
                    <label>تكلفة الوحدة ₪ <input id="inventorySetupCost" type="number" min="0" max="9999999.99" step="0.01" required></label>
                    <label>سعر البيع ₪ <input id="inventorySetupPrice" type="number" min="0" max="9999999.99" step="0.01" required></label>
                </div>
                </div>
                <p id="inventorySetupPriceHint" class="inventory-setup-hint">الاسم والكود والتصنيف تخص المنتج بكل ألوانه ومقاساته. سعر البيع مشترك لنفس اللون والمقاس في جميع الفروع. تغيير اللون لا يستبدل الصور المحفوظة تلقائيًا.</p>
                <p id="inventorySetupDeleteWarning" class="inventory-setup-warning" hidden>سيُحذف سطر المخزون من هذا الفرع ويُصحّح المستند الافتتاحي المرتبط به. تعريف المنتج وصوره وبقية الفروع تبقى محفوظة.</p>
                <label class="inventory-setup-reason">ملاحظة التصحيح (اختياري)<textarea id="inventorySetupReason" maxlength="500" rows="2" placeholder="مثال: تصحيح خطأ أثناء إدخال البضاعة"></textarea></label>
            </fieldset>
        </div>
        <footer><span id="inventorySetupStatus" role="status"></span><button type="button" class="btn btn-outline" data-setup-close>إلغاء</button><button id="inventorySetupSubmit" class="btn btn-primary" type="submit">حفظ التعديل</button></footer>
    </form>
</dialog>
<div id="inventorySetupFeedback" class="inventory-setup-feedback" role="status" hidden></div>
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/inventory/setup.css') }}">
@endpush
@push('scripts')
    <script type="application/json" id="inventory-setup-config">{!! json_encode(['csrf' => csrf_token(), 'options_url' => route('inventory.opening-stock.options')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('assets/admin/js/pages/inventory/setup.js') }}" defer></script>
@endpush