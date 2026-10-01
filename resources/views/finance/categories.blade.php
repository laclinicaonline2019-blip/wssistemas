@extends('layouts.app', ['title' => 'Plano de contas'])

@section('content')
<div class="page-head"><div><h1>Plano de contas</h1><p>Categorias de receitas e despesas. Desativar não afeta lançamentos antigos.</p></div>
    <a class="btn" href="{{ route('finance.overview') }}">Voltar</a></div>

<div class="grid grid-2">
    @foreach (\App\Modules\Finance\Models\FinancialCategory::TYPES as $type => $label)
        <section class="card">
            <div class="card__head"><h2>{{ $label }}s</h2></div>
            <div class="card__body stack">
                @foreach ($categories->where('type', $type) as $c)
                    <div class="spread {{ $c->is_active ? '' : 'muted' }}"><span>{{ $c->name }}{{ $c->is_active ? '' : ' (inativa)' }}</span>
                        <form method="post" action="{{ route('finance.categories.toggle', $c) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $c->is_active ? 'Desativar' : 'Reativar' }}</button></form></div>
                @endforeach
                <form method="post" action="{{ route('finance.categories.store') }}" class="row">
                    @csrf <input type="hidden" name="type" value="{{ $type }}">
                    <label class="sr-only" for="c-{{ $type }}">Nova categoria</label>
                    <input id="c-{{ $type }}" name="name" class="input" maxlength="80" required placeholder="Nova categoria de {{ mb_strtolower($label) }}">
                    <button class="btn" type="submit">Adicionar</button>
                </form>
            </div>
        </section>
    @endforeach
</div>
@endsection
