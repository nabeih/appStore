<form method="post" action="{{ route('catalog.attributes.colors.store') }}" data-attribute-form="colors" class="attribute-form">
    @csrf
    <h2>إضافة لون</h2>
    <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
    <fieldset>
        <x-catalog.color-editor id="create-color" :palette="$palette" :name-value="old('name', '')" :hex-value="old('hex_code', '')" />
        <details class="attribute-optional"><summary>رمز مختصر (اختياري)</summary><label for="color-code">رمز اللون</label><input id="color-code" name="code" dir="ltr" maxlength="20" value="{{ old('code') }}" placeholder="مثال: BLK — يجهّز تلقائيًا عند تركه فارغًا"></details>
        <button type="submit" class="btn btn-primary attribute-submit">إضافة اللون</button>
    </fieldset>
</form>