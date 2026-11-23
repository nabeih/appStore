<form method="post" action="{{ route('catalog.attributes.sizes.store') }}" data-attribute-form="sizes" class="attribute-form">
    @csrf
    <h2>إضافة مقاس</h2>
    <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
    <fieldset>
        <label for="size-name">اسم مقاس واحد</label><input id="size-name" name="name" value="{{ old('name') }}" maxlength="100" placeholder="مثال: S، XL، 38، عمر سنتين">
        <label for="size-batch">أو إضافة عدة مقاسات دفعة واحدة</label><textarea id="size-batch" name="batch_names" rows="3" placeholder="S&#10;M&#10;L&#10;XL">{{ old('batch_names') }}</textarea><small>مقاس في كل سطر أو افصل بفاصلة. اترك اسم المقاس المفرد والرمز فارغين عند استخدام المجموعة. الحد 50 مقاسًا.</small>
        <label for="size-order">ترتيب العرض (اختياري)</label><input id="size-order" type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="65535" step="1"><small>الأرقام الأصغر تظهر أولًا في قوائم المقاسات.</small>
        <details class="attribute-optional"><summary>رمز مختصر (اختياري)</summary><label for="size-code">رمز المقاس</label><input id="size-code" name="code" dir="ltr" maxlength="20" value="{{ old('code') }}" placeholder="مثال: XL — يجهّز تلقائيًا عند تركه فارغًا"></details>
        <button type="submit" class="btn btn-primary attribute-submit">إضافة المقاس</button>
    </fieldset>
</form>
