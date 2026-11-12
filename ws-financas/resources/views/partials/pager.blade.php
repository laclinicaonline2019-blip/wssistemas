@if ($paginator->hasPages())
<nav class="tools" aria-label="Paginação">
  @if ($paginator->previousPageUrl())<a class="btn sm" href="{{ $paginator->previousPageUrl() }}">‹ Anterior</a>@endif
  <span class="small muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
  @if ($paginator->nextPageUrl())<a class="btn sm" href="{{ $paginator->nextPageUrl() }}">Próxima ›</a>@endif
</nav>
@endif
