<div class="attribute-pagination">
    <span>{{ $entries->total() }} نتيجة — صفحة {{ $entries->currentPage() }} من {{ $entries->lastPage() }}</span>
    @if($entries->hasPages())
        <nav aria-label="صفحات النتائج">
            @if($entries->onFirstPage())<span class="attribute-page disabled">السابق</span>@else<a class="attribute-page" href="{{ $entries->previousPageUrl() }}" data-attribute-nav>السابق</a>@endif
            @for($page = max(1, $entries->currentPage() - 2); $page <= min($entries->lastPage(), $entries->currentPage() + 2); $page++)
                <a class="attribute-page {{ $page === $entries->currentPage() ? 'active' : '' }}" href="{{ $entries->url($page) }}" data-attribute-nav @if($page === $entries->currentPage()) aria-current="page" @endif>{{ $page }}</a>
            @endfor
            @if($entries->hasMorePages())<a class="attribute-page" href="{{ $entries->nextPageUrl() }}" data-attribute-nav>التالي</a>@else<span class="attribute-page disabled">التالي</span>@endif
        </nav>
    @endif
</div>