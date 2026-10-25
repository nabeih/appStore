@props(['id', 'palette', 'nameValue' => '', 'hexValue' => '', 'readonlyName' => false, 'hexRequired' => true, 'presetNames' => true])
@php($hexValue = is_string($hexValue) ? $hexValue : '')
@php($nameValue = is_string($nameValue) ? $nameValue : '')
<div data-color-editor data-preset-names="{{ $presetNames ? 'true' : 'false' }}">
    <label for="{{ $id }}-name">اسم اللون</label>
    <input id="{{ $id }}-name" name="name" value="{{ $nameValue }}" maxlength="100" required data-color-name @readonly($readonlyName) placeholder="مثال: مريمي، موكا، وردي غباري">
    <div class="attribute-color-controls">
        <label class="attribute-picker-label" for="{{ $id }}-picker">اختر الدرجة
            <input id="{{ $id }}-picker" type="color" value="{{ preg_match('/^#[0-9a-f]{6}$/i', $hexValue) ? $hexValue : '#808080' }}" data-color-picker>
        </label>
        <label class="attribute-hex-label" for="{{ $id }}-hex">درجة اللون{{ $hexRequired ? '' : ' (اختياري)' }}
            <input id="{{ $id }}-hex" name="hex_code" value="{{ $hexValue }}" dir="ltr" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#2563EB" autocomplete="off" spellcheck="false" @required($hexRequired) data-color-hex>
        </label>
        <span class="attribute-color-preview" data-color-preview aria-label="معاينة اللون"></span>
    </div>
    <p class="attribute-color-message" data-color-message aria-live="polite">اكتب اسم اللون لاقتراح درجته، أو اختر الدرجة بنفسك.</p>
    <button type="button" class="btn btn-outline btn-sm" data-color-suggest>اقتراح الدرجة من الاسم</button>
    <section class="attribute-palette-library" aria-label="مكتبة درجات الألوان">
        <header class="attribute-palette-heading"><strong>درجات جاهزة</strong><small>{{ count($palette) }} درجة في {{ count(array_unique(array_column($palette, 'family'))) }} مجموعات</small></header>
        <div class="attribute-palette-filters">
            <label class="attribute-palette-search-label" for="{{ $id }}-palette-search">بحث عن درجة
                <input id="{{ $id }}-palette-search" type="search" data-palette-search autocomplete="off" placeholder="مريمي، موكا، dusty rose…">
            </label>
            <label for="{{ $id }}-palette-family">عائلة اللون
                <select id="{{ $id }}-palette-family" data-palette-family><option value="">كل الألوان</option>@foreach(array_unique(array_column($palette, 'family')) as $family)<option value="{{ $family }}">{{ $family }}</option>@endforeach</select>
            </label>
        </div>
        <p class="attribute-palette-count" data-palette-count aria-live="polite"></p>
        <div class="attribute-palette" aria-label="الألوان الجاهزة">
            @foreach($palette as $preset)
                <button type="button" data-color-preset="{{ $preset['hex'] }}" data-color-label="{{ $preset['name'] }}" data-color-family="{{ $preset['family'] }}" data-color-search="{{ implode(' ', [$preset['name'], ...$preset['aliases']]) }}" title="{{ $preset['name'].' '.$preset['hex'] }}" aria-label="اختيار {{ $preset['name'] }}" aria-pressed="false"><x-catalog.color-dot :hex="$preset['hex']" :label="$preset['name']" /><span>{{ $preset['name'] }}</span></button>
            @endforeach
        </div>
        <p class="attribute-palette-empty" data-palette-empty hidden>لا توجد درجة مطابقة. يمكنك اختيار أي درجة من منتقي اللون.</p>
        <small class="attribute-muted">{{ $presetNames ? 'اختيار درجة جاهزة يملأ اسم اللون ودرجته. ويمكنك تخصيصهما.' : 'اختيار درجة جاهزة يغيّر الدرجة مع الاحتفاظ بالاسم الذي تكتبه.' }}</small>
    </section>
</div>