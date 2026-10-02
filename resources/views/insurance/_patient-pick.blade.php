{{-- Escolha do paciente por busca (nome, CPF, telefone ou prontuário). --}}
@php $pq = request('pq'); $found = $pq ? \App\Modules\Patients\Models\Patient::query()->search($pq)->where('status', 'active')->orderBy('name')->limit(10)->get() : collect(); @endphp
<form method="get" class="row mb-1">
    <label class="sr-only" for="pq">Paciente</label>
    <input id="pq" name="pq" value="{{ $pq }}" class="input" placeholder="Buscar paciente: nome, CPF, telefone ou nº do prontuário" minlength="2" required>
    <button class="btn" type="submit">Buscar</button>
</form>
@if ($pq)
    <ul class="stack small">
        @forelse ($found as $f)<li><a href="{{ request()->url() }}?patient_id={{ $f->id }}">#{{ $f->record_number }} — {{ $f->displayName() }}</a></li>
        @empty<li class="muted">Nenhum paciente encontrado.</li>@endforelse
    </ul>
@endif
