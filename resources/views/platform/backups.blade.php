@extends('layouts.app', ['title' => 'Backups'])

@section('content')
<div class="page-head">
    <div><h1>Backups</h1><p>Gerados automaticamente pelo cron (banco todo dia às 02:30; anexos aos domingos). Baixe e guarde cópias <strong>fora do servidor</strong>.</p></div>
    <form method="post" action="{{ route('platform.backups.store') }}" class="row">
        @csrf
        <label class="row small"><input type="checkbox" name="files" value="1"> incluir anexos</label>
        <button class="btn btn-primary">Gerar backup agora</button>
    </form>
</div>
@if (! $encrypted)<div class="alert alert-warning">Sem <span class="mono">BACKUP_PASSWORD</span> no .env: os backups <strong>não estão criptografados</strong>. Defina uma senha forte e guarde-a em cofre separado.</div>@endif
<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Arquivo</th><th>Tipo</th><th>Tamanho</th><th>Gerado em</th><th></th></tr></thead>
        <tbody>
        @forelse ($backups as $b)
            <tr><td class="mono small">{{ $b['name'] }}</td>
                <td>{{ str_contains($b['name'], '-db-') ? 'Banco de dados' : 'Anexos' }} @if (str_ends_with($b['name'], '.enc'))<span class="badge badge-success">criptografado</span>@endif</td>
                <td>{{ number_format($b['size'] / 1048576, 2, ',', '.') }} MB</td>
                <td>{{ \Carbon\Carbon::createFromTimestamp($b['time'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                <td><a class="btn btn-sm" href="{{ route('platform.backups.download', $b['name']) }}">Baixar</a></td></tr>
        @empty
            <tr><td colspan="5" class="muted">Nenhum backup ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
<p class="small muted mt-2">Restauração: veja <span class="mono">docs/PRODUCAO.md</span> (descriptografar com <span class="mono">php artisan aivexa:backup --decrypt=arquivo.enc</span> e importar com <span class="mono">mysql</span> ou phpMyAdmin).</p>
@endsection
