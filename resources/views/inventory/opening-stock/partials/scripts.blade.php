@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/inventory/opening-stock.css') }}">
@endpush
@push('scripts')
    <script type="application/json" id="opening-stock-config">{!! json_encode([
        'imageResolveUrl' => route('inventory.opening-stock.images.resolve'),
        'optionsUrl' => route('inventory.opening-stock.options'),
        'optionsStoreUrl' => route('inventory.opening-stock.options.store'),
        'productsUrl' => route('inventory.opening-stock.products'),
        'previewUrl' => route('inventory.opening-stock.preview'),
        'draftUrl' => route('inventory.opening-stock.drafts.store'),
        'saveUrl' => route('inventory.opening-stock.store'),
        'csrf' => csrf_token(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('assets/admin/js/pages/inventory/opening-stock.js') }}" defer></script>
@endpush