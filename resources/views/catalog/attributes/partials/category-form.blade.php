<form method="post" action="{{ route('catalog.attributes.categories.store') }}" data-attribute-form="categories"
    class="attribute-form">
    @csrf
    <h2>إضافة تصنيف</h2>
    <div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div>
    <fieldset>
        <label for="category-name">اسم التصنيف</label><input id="category-name" name="name" maxlength="100"
            value="{{ old('name') }}" placeholder="مثال: ملابس أطفال" required>
        <label for="category-parent">التصنيف الرئيسي (اختياري)</label>
        <select id="category-parent" name="parent_id">
            <option value="">تصنيف رئيسي مستقل</option>@foreach($parents as $parent)
                <option value="{{ $parent->id }}" @selected(old('parent_id') == $parent->id)>
            {{ $parent->name }}@if($parent->parent) — {{ $parent->parent->name }}@endif</option>@endforeach
        </select>
        <label for="category-description">وصف (اختياري)</label><textarea id="category-description" name="description"
            rows="3" maxlength="2000" placeholder="وصف يساعدك بتنظيم المنتجات">{{ old('description') }}</textarea>
        <button type="submit" class="btn btn-primary attribute-submit">إضافة التصنيف</button>
    </fieldset>
</form>