@extends('layouts.app', ['title' => $user->exists ? $user->name : 'Novo usuário'])

@php
    $me = auth()->user();
    $isSelf = $user->exists && $me->is($user);
    $assignments = old('roles', $user->exists ? $user->roleAssignments->map(fn ($a) => ['role_id' => $a->role_id, 'branch_id' => $a->branch_id])->all() : [[]]);
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $user->exists ? $user->name : 'Novo usuário' }}</h1>
        <p>@if ($user->exists) {{ $user->email }} ·
            @if ($user->status === 'active')<span class="badge badge-success">Ativo</span>@else<span class="badge badge-danger">Bloqueado</span>@endif
            @if ($user->hasTwoFactorEnabled())<span class="badge badge-success">2FA</span>@endif
        @else A senha inicial deverá ser trocada pelo usuário no primeiro acesso. @endif</p></div>
    <a class="btn" href="{{ route('users.index') }}">Voltar</a>
</div>

<template id="role-row-tpl">@include('users._role-row', ['index' => '__INDEX__', 'assignment' => []])</template>

<div class="grid grid-2">
    <form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card">
        @csrf
        @if ($user->exists) @method('put') @endif
        <div class="card__head"><h2>Dados de acesso</h2></div>
        <div class="card__body">
            <div class="form-grid">
                <x-field name="name" label="Nome completo" :value="$user->name" col="col-12" required />
                <x-field name="email" label="E-mail (login)" type="email" :value="$user->email" col="col-6" required autocomplete="off" />
                <x-field name="phone" label="Telefone" :value="$user->phone" col="col-6" mask="phone" />
                <x-field name="password" :label="$user->exists ? 'Redefinir senha (opcional)' : 'Senha inicial'" type="password" col="col-6" :required="! $user->exists" autocomplete="new-password" />
                <x-field name="password_confirmation" label="Confirmar senha" type="password" col="col-6" :required="! $user->exists" autocomplete="new-password" />
            </div>

            @unless ($user->exists)
                <h3 class="mt-2">Perfis de acesso</h3>
                <div id="role-rows" class="stack">
                    @foreach ($assignments as $i => $a)
                        @include('users._role-row', ['index' => $i, 'assignment' => $a])
                    @endforeach
                </div>
                <button type="button" class="btn btn-sm mt-1" data-row-add="#role-rows" data-row-template="#role-row-tpl"><svg><use href="#i-plus"/></svg>Adicionar perfil</button>
            @endunless

            @if (! $user->exists || $me->hasPermission('usuario.editar'))
                <div class="form-actions"><button class="btn btn-primary" type="submit">{{ $user->exists ? 'Salvar alterações' : 'Criar usuário' }}</button></div>
            @endif
        </div>
    </form>

    @if ($user->exists)
        <div>
            <form method="post" action="{{ route('users.roles', $user) }}" class="card">
                @csrf @method('put')
                <div class="card__head"><h2>Perfis de acesso</h2></div>
                <div class="card__body">
                    @if ($isSelf)
                        <p class="text-2">Você não pode alterar os próprios perfis. Peça a outro administrador.</p>
                    @endif
                    <div id="role-rows" class="stack">
                        @foreach ($assignments as $i => $a)
                            @include('users._role-row', ['index' => $i, 'assignment' => $a])
                        @endforeach
                    </div>
                    @if (! $isSelf && $me->hasPermission('usuario.perfis'))
                        <button type="button" class="btn btn-sm mt-1" data-row-add="#role-rows" data-row-template="#role-row-tpl"><svg><use href="#i-plus"/></svg>Adicionar perfil</button>
                        <p class="help mt-1">Só é possível conceder perfis cujas permissões você também possui.</p>
                        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar perfis</button></div>
                    @endif
                </div>
            </form>

            @if (! $isSelf && $me->hasPermission('usuario.bloquear'))
                <section class="card">
                    <div class="card__head"><h2>Acesso</h2></div>
                    <div class="card__body">
                        @if ($user->status === 'active')
                            <p class="text-2">Bloquear encerra imediatamente as sessões e tokens de API do usuário.</p>
                            <form method="post" action="{{ route('users.block', $user) }}" data-confirm="Bloquear {{ $user->name }}?">@csrf
                                <button class="btn btn-danger" type="submit">Bloquear usuário</button></form>
                        @else
                            <form method="post" action="{{ route('users.unblock', $user) }}">@csrf
                                <button class="btn btn-primary" type="submit">Desbloquear usuário</button></form>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    @endif
</div>
@endsection
