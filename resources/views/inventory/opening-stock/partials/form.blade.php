<x-catalog.color-image-assets />
<dialog id="quickOpeningStockModal" class="os-dialog" aria-labelledby="osDialogTitle">
    <header class="os-dialog-header">
        <div><h2 id="osDialogTitle">إدخال البضاعة الافتتاحية</h2><p>سجّل بضاعتك الموجودة بدون فاتورة شراء.</p></div>
        <button type="button" class="btn btn-outline" id="osClose" aria-label="إغلاق">✕</button>
    </header>
    <div class="os-dialog-body">
        <div id="openingStockErrors" class="os-errors" role="alert" tabindex="-1" hidden></div>
        <fieldset id="osFields">
            <div class="os-fields">
                <label>الفرع <select id="openingStockBranch" class="quick-stock-select">
                    @forelse ($openingStockBranches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @empty
                        <option value="">لا يوجد فرع فعال</option>
                    @endforelse
                </select></label>
                <label>تاريخ الرصيد <input id="openingStockDate" type="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}"></label>
                <details class="os-grow os-inline-notes"><summary>ملاحظات (اختياري)</summary><input id="openingStockNotes" aria-label="ملاحظات المستند" maxlength="2000" placeholder="مثال: جرد البضاعة عند بدء العمل"></details>
            </div>
            <details class="os-notice"><summary>عن الإدخال الافتتاحي</summary><p>كل صنف يُدخل مرة واحدة لكل فرع. المستند المعتمد محفوظ للمراجعة ولا يُعدّل من هذه الشاشة.</p></details>
            <section class="os-section" aria-labelledby="osMatrixTitle">
                <h3 id="osMatrixTitle">1. إضافة الأصناف</h3>
                <label>اختيار منتج موجود <input type="search" id="osProductSearch" autocomplete="off" placeholder="ابحث بحرفين أو أكثر من الاسم أو الكود"></label>
                <div id="osProductResults" class="os-search-results" aria-live="polite"></div>
                <div class="os-fields">
                    <label class="os-field-wide">اسم المنتج <input id="matrixProductName" maxlength="200" placeholder="مثال: قميص قطني"></label>
                    <label>كود المنتج <input id="matrixProductCode" maxlength="50" dir="ltr" placeholder="اختياري للمنتج الجديد"></label>
                    <label>التصنيف <select id="matrixCategory"></select></label>
                    <label>الألوان <select id="matrixColors" multiple size="4" aria-describedby="osMultiHelp"></select></label>
                    <label>المقاسات <select id="matrixSizes" multiple size="4" aria-describedby="osMultiHelp"></select></label>
                </div>
                <small id="osMultiHelp">اختر الألوان والمقاسات المطلوبة. يمكنك اختيار أكثر من خيار.</small>
                <div class="os-fields os-defaults">
                    <label>الكمية لكل تركيبة <input id="matrixQty" type="number" min="1" max="1000000" step="1" placeholder="اختياري"></label>
                    <label>تكلفة الوحدة ₪ <input id="matrixCost" type="number" min="0" max="9999999.99" step="0.01" placeholder="اختياري"></label>
                    <label>سعر البيع ₪ <input id="matrixPrice" type="number" min="0" max="9999999.99" step="0.01" placeholder="للتركيبات الجديدة"></label>
                    <button type="button" class="btn btn-primary" id="osGenerate">إضافة التركيبات للجدول</button>
                </div>
            </section>
            <details class="os-section">
                <summary>تحتاج تصنيفًا أو لونًا أو مقاسًا جديدًا؟</summary>
                <div class="os-fields os-defaults">
                    <label>النوع <select id="osOptionType"><option value="category">تصنيف</option><option value="color">لون</option><option value="size">مقاس</option></select></label>
                    <label>الاسم <input id="osOptionName" maxlength="100"></label>
                    <label id="osOptionCodeLabel" hidden>الكود <input id="osOptionCode" dir="ltr" maxlength="20" placeholder="مثال: BLK أو XL"></label>
                    <button type="button" class="btn btn-outline" id="osAddOption">إضافة للقائمة</button>
                </div>
            </details>
            <details class="os-section"><summary>استيراد من Excel / CSV</summary>
            <div class="os-toolbar">
                <button type="button" class="btn btn-outline btn-sm" id="osDownload">تحميل نموذج CSV</button>
                <label class="btn btn-outline btn-sm os-upload">استيراد CSV <input id="openingStockCsvInput" type="file" accept=".csv,text/csv" hidden></label>
                <span>ملف CSV يمكن فتحه وتعبئته باستخدام Excel.</span>
            </div>
            </details>
            <h3 class="os-lines-title">2. الأصناف المدخلة</h3>
            <div class="os-table-wrap">
                <table class="quick-stock-table os-entry-table" role="table" aria-label="أصناف البضاعة الافتتاحية">
                    <thead><tr><th>#</th><th>الكود</th><th>اسم المنتج</th><th>التصنيف</th><th>اللون</th><th>المقاس</th><th>الكمية</th><th>تكلفة الوحدة ₪</th><th>سعر البيع ₪</th><th>حذف</th></tr></thead>
                    <tbody id="quickOpeningStockTableBody"></tbody>
                </table>
            </div>
            <div class="os-toolbar"><button type="button" class="btn btn-outline" id="osAddRow">إضافة سطر</button><span id="quickStockRowCount" aria-live="polite"></span></div>
            <section id="osColorImagesSection" class="os-section" hidden>
                <h3>3. صور ألوان المنتجات</h3>
                <p class="color-images-help">صورة اختيارية لكل منتج ولون، مشتركة بين جميع مقاساته. الصيغ: JPG وPNG وWebP، بحد أقصى 4 ميجابايت.</p>
                <div id="osColorImages" class="color-images-grid"></div>
            </section>
            <small>سعر الصنف الموجود يبقى كما هو في بقية الفروع.</small>
        </fieldset>
        <section id="osPreview" class="os-section os-preview" aria-live="polite" hidden>
            <h3>مراجعة الرصيد قبل الاعتماد</h3>
            <div id="osPreviewTotals"></div>
            <div class="os-table-wrap mobile-records-wrapper"><table class="mobile-records" role="table"><thead><tr><th>المنتج</th><th>اللون / المقاس</th><th>الكمية</th><th>تكلفة الوحدة</th><th>قيمة البضاعة</th></tr></thead><tbody id="osPreviewRows"></tbody></table></div>
            <p>الاعتماد يضيف البضاعة للمخزون ويسجل مستندها وحركاتها. لا ينشئ فاتورة شراء أو دينًا أو حركة صندوق.</p>
        </section>
    </div>
    <footer class="os-dialog-footer">
        <span id="osStatus" role="status"></span>
        <button type="button" class="btn btn-outline" id="osCancel">إغلاق</button>
        <button type="button" class="btn btn-primary" id="osReview">مراجعة الرصيد</button>
        <button type="button" class="btn btn-success" id="osSave" disabled>اعتماد البضاعة</button>
    </footer>
</dialog>
@include('inventory.opening-stock.partials.scripts')