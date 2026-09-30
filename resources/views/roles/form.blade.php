@extends('layouts.app', ['title' => $role->exists ? $role->name : 'Novo perfil'])

@php
    $editable = ! $role->is_locked && auth()->user()->hasPermission('perfil.gerenciar');
    $selected = old('permissions', $selected);
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $role->exists ? $role->name : 'Novo perfil' }}</h1>
        <p>@if ($role->is_locked) Perfil protegido: possui todas as permissões da clínica e não pode ser alterado.
           @else Marque as permissões deste perfil. Você só pode conceder permissões que também possui. @endif</p></div>
    <a class="btn" href="{{ route('roles.index') }}">Voltar</a>
</div>

<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">
    @csrf
    @if ($role->exists) @method('put') @endif
    <section class="card">
        <div class="card__body form-grid">
            <x-field name="name" label="Nome do perfil" :value="$role->name" col="col-4" required :disabled="! $editable" />
            <x-field name="description" label="Descrição" :value="$role->description" col="col-8" :disabled="! $editable" />
        </div>
    </section>

    <div class="perm-grid mt-2">
        @foreach ($modules as $moduleKey => $module)
            <fieldset class="perm-module">
                <div class="perm-module__head">
                    <legend>{{ $module['label'] }}</legend>
                    @if ($editable)<button type="button" class="btn btn-ghost btn-sm" data-check-all>Todas</button>@endif
                </div>
                @foreach ($module['permissions'] as $key => $label)
                    <label class="check small">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $selected, true)) @disabled(! $editable)>
                        <span>{{ $label }}<br><code class="muted">{{ $key }}</code></span>
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    </div>

    @if ($editable)
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar perfil</button></div>
    @endif
</form>
@endsection
