@foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] as $key => $type)
    @if (session($key))
        <div class="alert alert-{{ $type }}" role="{{ $type === 'error' ? 'alert' : 'status' }}">{{ session($key) }}</div>
    @endif
@endforeach
@if ($errors->any() && ! ($hideErrorSummary ?? false))
    <div class="alert alert-error" role="alert">
        Verifique os campos destacados.
        @if ($errors->count() <= 3)
            <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        @endif
    </div>
@endif
