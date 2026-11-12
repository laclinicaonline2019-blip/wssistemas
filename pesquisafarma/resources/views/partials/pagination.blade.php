@if ($paginator->hasPages())
<div class="pagination">
  @if (! $paginator->onFirstPage())<a href="{{ $paginator->previousPageUrl() }}">‹ Anterior</a>@endif
  <span class="on">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
  @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Próxima ›</a>@endif
</div>
@endif
