@props(['hex' => null, 'label' => ''])
@php($validHex = is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) ? strtoupper($hex) : null)
<span {{ $attributes->class(['catalog-color-dot', 'catalog-color-unknown' => !$validHex]) }} @if($validHex) style="background-color:{{ $validHex }}" @endif title="{{ $label }}" aria-hidden="true"></span>