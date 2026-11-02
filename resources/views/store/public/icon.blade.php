<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shop-icon">
@switch($name)
@case('bag')
<path d="M6 7h12l2 14H4L6 7Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>
@break
@case('search')
<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>
@break
@case('menu')
<path d="M4 6h16M4 12h16M4 18h16"/>
@break
@case('close')
<path d="m6 6 12 12M18 6 6 18"/>
@break
@case('arrow')
<path d="M20 12H4m6-6-6 6 6 6"/>
@break
@case('location')
<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/>
@break
@case('phone')
<path d="M7 3H4c-3 7 9 19 16 16v-3l-5-2-2 2a15 15 0 0 1-5-5l2-2-3-6Z"/>
@break
@case('hanger')
<path d="M10 6a2 2 0 1 1 3 2l-1 1v3L3 17v2h18v-2l-9-5"/>
@break
@case('check')
<path d="m5 12 4 4L20 5"/>
@break
@case('filter')
<path d="M3 6h18M6 12h12M9 18h6"/><circle cx="7" cy="6" r="2" fill="white"/><circle cx="16" cy="12" r="2" fill="white"/>
@break
@default
<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>
@endswitch
</svg>