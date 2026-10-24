<form method="post" action="{{ route('catalog.attributes.sizes.store') }}" data-attribute-form="sizes" class="attribute-form">
    @csrf
    <h2>إضافة مقاس</h2>
    <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
    <fieldset>
        <label for="size-name">اسم المقاس</label><input id="size-name" name="name" value="{{ old('name') }}" maxlength="100" placeholder="مثال: S، XL، 38، عمر سنتين" required>
        <label for="size-order">ترتيب العرض (اختياري)</label><input id="size-order" type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="65535" step="1"><small>الأرقام الأصغر تظهر أولًا في قوائم المقاسات.</small>
        <details class="attribute-optional"><summary>رمز مختصر (اختياري)</summary><label for="size-code">رمز المقاس</label><input id="size-code" name="code" dir="ltr" maxlength="20" value="{{ old('code') }}" placeholder="مثال: XL — يجهّز تلقائيًا عند تركه فارغًا"></details>
        <button type="submit" class="btn btn-primary attribute-submit">إضافة المقاس</button>
    </fieldset>
</form>