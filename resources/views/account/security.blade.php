@extends('layouts.app', ['title' => 'Minha conta'])

@section('content')
<div class="page-head">
    <div><h1>Minha conta e segurança</h1><p>{{ $user->name }} · {{ $user->email }}</p></div>
</div>

@if ($user->must_change_password)
    <div class="alert alert-warning">Por segurança, defina uma nova senha antes de continuar.</div>
@endif

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Alterar senha</h2></div>
        <div class="card__body">
            <form method="post" action="{{ route('account.password') }}" class="stack">
                @csrf @method('put')
                <div class="field">
                    <label for="current_password">Senha atual</label>
                    <input id="current_password" name="current_password" type="password" class="input @error('current_password') is-invalid @enderror" autocomplete="current-password" required>
                    @error('current_password')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password">Nova senha</label>
                    <input id="password" name="password" type="password" class="input @error('password') is-invalid @enderror" autocomplete="new-password" required>
                    <div class="help">Mínimo {{ config('aivexa.security.password_min_length') }} caracteres, com maiúsculas, minúsculas, números e símbolos.</div>
                    @error('password')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirme a nova senha</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar nova senha</button></div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2>Autenticação em dois fatores (2FA)</h2>
            @if ($user->hasTwoFactorEnabled())
                <span class="badge badge-success">Ativa</span>
            @else
                <span class="badge badge-warning">Inativa</span>
            @endif
        </div>
        <div class="card__body stack">
            @if ($recoveryCodes)
                <div class="alert alert-info">Guarde estes códigos de recuperação em local seguro. Cada um pode ser usado uma única vez e eles <strong>não serão exibidos novamente</strong>.</div>
                <div class="codes" id="recovery-codes">@foreach ($recoveryCodes as $c)<span>{{ $c }}</span>@endforeach</div>
                <button type="button" class="btn btn-sm" data-copy="#recovery-codes">Copiar códigos</button>
            @endif

            @if ($user->hasTwoFactorEnabled())
                <p class="text-2">Seu login exige um código do aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).</p>
                @unless ($user->is_super_admin && config('aivexa.security.super_admin_requires_2fa'))
                    <form method="post" action="{{ route('account.2fa.disable') }}" class="stack" data-confirm="Desativar a autenticação em dois fatores reduz a segurança da sua conta. Continuar?">
                        @csrf
                        <div class="grid grid-2">
                            <div class="field"><label for="d-pass">Senha</label><input id="d-pass" name="password" type="password" class="input" required></div>
                            <div class="field"><label for="d-code">Código atual</label><input id="d-code" name="code" class="input @error('code') is-invalid @enderror" inputmode="numeric" maxlength="6" required></div>
                        </div>
                        @error('code')<div class="field-error">{{ $message }}</div>@enderror
                        <div><button class="btn btn-danger btn-sm" type="submit">Desativar 2FA</button></div>
                    </form>
                @endunless
            @elseif ($qrSvg)
                <p class="text-2">1. Escaneie o QR code no aplicativo autenticador. 2. Informe o código gerado para confirmar.</p>
                <div class="row">
                    <div class="qr">{!! $qrSvg !!}</div>
                </div>
                <form method="post" action="{{ route('account.2fa.confirm') }}" class="row">
                    @csrf
                    <label class="sr-only" for="c-code">Código</label>
                    <input id="c-code" name="code" class="input w-auto @error('code') is-invalid @enderror" inputmode="numeric" maxlength="6" placeholder="000000" required autofocus>
                    <button class="btn btn-primary" type="submit">Confirmar</button>
                </form>
                @error('code')<div class="field-error">{{ $message }}</div>@enderror
            @else
                <p class="text-2">Adicione uma segunda camada de proteção. Recomendado para todos os usuários e obrigatório para administradores da plataforma.</p>
                <form method="post" action="{{ route('account.2fa.enable') }}" class="row">
                    @csrf
                    <label class="sr-only" for="e-pass">Senha</label>
                    <input id="e-pass" name="password" type="password" class="input w-auto @error('password') is-invalid @enderror" placeholder="Confirme sua senha" required>
                    <button class="btn btn-primary" type="submit"><svg><use href="#i-lock"/></svg>Configurar 2FA</button>
                </form>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            @endif
        </div>
    </section>
</div>
@endsection
