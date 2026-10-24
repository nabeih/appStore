@props(['id', 'palette', 'nameValue' => '', 'hexValue' => '', 'readonlyName' => false])
@php($hexValue = is_string($hexValue) ? $hexValue : '')
@php($nameValue = is_string($nameValue) ? $nameValue : '')
<div data-color-editor>
    <label for="{{ $id }}-name">اسم اللون</label>
    <input id="{{ $id }}-name" name="name" value="{{ $nameValue }}" maxlength="100" required data-color-name @readonly($readonlyName) placeholder="مثال: أحمر، كحلي، بيج">
    <div class="attribute-color-controls">
        <label class="attribute-picker-label" for="{{ $id }}-picker">اختر الدرجة
            <input id="{{ $id }}-picker" type="color" value="{{ preg_match('/^#[0-9a-f]{6}$/i', $hexValue) ? $hexValue : '#808080' }}" data-color-picker>
        </label>
        <label class="attribute-hex-label" for="{{ $id }}-hex">درجة اللون
            <input id="{{ $id }}-hex" name="hex_code" value="{{ $hexValue }}" dir="ltr" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#2563EB" autocomplete="off" spellcheck="false" required data-color-hex>
        </label>
        <span class="attribute-color-preview" data-color-preview aria-label="معاينة اللون"></span>
    </div>
    <p class="attribute-color-message" data-color-message aria-live="polite">اكتب اسم لون شائع لاقتراح درجته، أو اختر الدرجة بنفسك.</p>
    <button type="button" class="btn btn-outline btn-sm" data-color-suggest>اقتراح الدرجة من الاسم</button>
    <div class="attribute-palette" aria-label="ألوان جاهزة">
        @foreach($palette as $preset)
            <button type="button" data-color-preset="{{ $preset['hex'] }}" data-color-label="{{ $preset['name'] }}" title="{{ $preset['name'] }}" aria-label="اختيار {{ $preset['name'] }}"><x-catalog.color-dot :hex="$preset['hex']" :label="$preset['name']" /></button>
        @endforeach
    </div>
</div>