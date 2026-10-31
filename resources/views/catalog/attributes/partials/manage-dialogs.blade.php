<dialog id="attribute-edit-dialog" class="attribute-dialog" aria-labelledby="attribute-edit-title">
    <form method="post" id="attribute-edit-form" data-attribute-update-form>
        @csrf @method('PUT')
        <header><h2 id="attribute-edit-title">تعديل العنصر</h2><button type="button" class="btn btn-outline btn-sm" data-close-attribute-edit aria-label="إغلاق">✕</button></header>
        <div class="attribute-dialog-body">
            <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
            <fieldset>
                <label for="attribute-edit-name">الاسم</label><input id="attribute-edit-name" name="name" maxlength="100" required>
                <div data-size-edit-field hidden><label for="attribute-edit-sort">ترتيب العرض</label><input id="attribute-edit-sort" name="sort_order" type="number" min="0" max="65535" step="1" inputmode="numeric" disabled>
                    <p class="attribute-muted">رمز المقاس وأكواد الأصناف ثابتة. الفواتير السابقة تحتفظ باسم المقاس المحفوظ.</p>
                </div>
                <div data-category-edit-field hidden><label for="attribute-edit-description">الوصف (اختياري)</label><textarea id="attribute-edit-description" name="description" rows="3" maxlength="2000" disabled></textarea></div>
            </fieldset>
        </div>
        <footer><button type="button" class="btn btn-outline" data-close-attribute-edit>إلغاء</button><button type="submit" class="btn btn-primary">حفظ التعديل</button></footer>
    </form>
</dialog>
<dialog id="attribute-delete-dialog" class="attribute-dialog attribute-confirm-dialog" aria-labelledby="attribute-delete-title">
    <form method="post" id="attribute-delete-form" data-attribute-delete-form>
        @csrf @method('DELETE')
        <header><h2 id="attribute-delete-title">حذف العنصر</h2><button type="button" class="btn btn-outline btn-sm" data-close-attribute-delete aria-label="إغلاق">✕</button></header>
        <div class="attribute-dialog-body">
            <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
            <p>حذف «<strong data-delete-attribute-name></strong>» من القائمة؟</p>
            <p class="attribute-muted">الحذف متاح للعناصر غير المرتبطة بمنتجات أو أصناف أو تصنيفات فرعية فقط.</p>
        </div>
        <footer><button type="button" class="btn btn-outline" data-close-attribute-delete>إلغاء</button><button type="submit" class="btn attribute-confirm-delete">تأكيد الحذف</button></footer>
    </form>
</dialog>
