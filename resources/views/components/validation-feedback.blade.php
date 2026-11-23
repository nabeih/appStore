<link rel="stylesheet" href="{{ asset('assets/admin/css/validation.css') }}">
<script type="application/json" data-validation-errors>{!! json_encode($errors->getBag('default')->messages(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="{{ asset('assets/admin/js/validation.js') }}" defer></script>
