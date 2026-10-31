@can('catalog.search')@can('inventory.view')
<section class="product-search-panel" data-product-search data-url="{{ route('search.products') }}">
    <h2>بحث المنتجات والمسح</h2><form data-product-search-form role="search"><label>الاسم أو كود المنتج أو SKU أو اللون والمقاس<input id="{{ $inputId ?? 'product-query' }}" name="q" type="search" maxlength="100" autocomplete="off" value="{{ request('q') }}" data-product-query></label>
    <label>الفرع<select name="branch_id" data-product-branch><option value="">الفروع المسموحة</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label><button class="btn btn-primary" type="submit">بحث</button></form>
    @include('search.partials.product-tools', ['target' => '#'.($inputId ?? 'product-query')])
    <p data-product-status role="status">اكتب للبحث أو امسح SKU؛ اختيار النتيجة لا يعتمد البيع.</p><div data-product-results class="product-search-results"></div><button type="button" class="btn btn-outline btn-sm" data-product-more hidden>المزيد</button>
</section>
@endcan @endcan
