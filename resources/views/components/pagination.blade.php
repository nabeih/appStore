@props(['paginator'])
<div class="document-pagination">
    <span>{{ $paginator->total() }} نتيجة · {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
    @if($paginator->hasPages())
        <nav aria-label="صفحات النتائج">
            @if($paginator->previousPageUrl())<a class="btn btn-outline btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">السابق</a>@endif
            @if($paginator->nextPageUrl())<a class="btn btn-outline btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">التالي</a>@endif
        </nav>
    @endif
</div>
