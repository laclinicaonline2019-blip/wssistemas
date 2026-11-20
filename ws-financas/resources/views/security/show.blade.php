@extends(isset($account) ? 'layouts.app' : 'layouts.guest')
@section('title', 'Segurança')
@section('content')
<div class="grid2">
  <div class="card"><h2>Verificação em duas etapas</h2>
    @if ($user->hasTwoFactor())
      <p class="mt0"><span class="pill ok">Ativa</span> desde {{ $user->two_factor_confirmed_at->format('d/m/Y') }}.</p>
      @unless ($user->requiresTwoFactor())
        <form method="post" action="{{ route('security.2fa.disable') }}" class="form">@csrf @method('DELETE')
          <p class="full small mt0">Para desativar, confirme com a senha atual e com o código do aplicativo autenticador (ou um código de recuperação).</p>
          <div class="field"><label for="pw2">Senha atual</label><input id="pw2" type="password" name="password" required autocomplete="current-password"></div>
          <div class="field"><label for="cd2">Código do aplicativo</label><input id="cd2" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20"></div>
          <div class="formacts full"><button class="btn danger" type="submit">Desativar</button></div></form>
      @else
        <p class="small muted">Obrigatória para administradores da plataforma.</p>
      @endunless
    @else
      <p class="mt0"><span class="pill warn">Desativada</span> Você entra só com e-mail e senha.</p>
      <p class="mt0 muted">Recomendado: proteja sua conta com um código do aplicativo autenticador do celular, além da senha.</p>
      <a class="btn primary" href="{{ route('security.2fa') }}">Ativar agora</a>
    @endif
  </div>
  <form class="card" method="post" action="{{ route('security.password') }}">@csrf
    <h2>Alterar senha</h2>
    <div class="field"><label for="cp">Senha atual</label><input id="cp" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label for="np">Nova senha</label><input id="np" type="password" name="password" required autocomplete="new-password"></div>
    <div class="field"><label for="np2">Repita a nova senha</label><input id="np2" type="password" name="password_confirmation" required autocomplete="new-password"></div>
    <button class="btn primary" type="submit">Alterar senha</button>
  </form>
</div>
<p class="small muted">Último acesso: {{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }} {{ $user->last_login_ip ? '· IP '.$user->last_login_ip : '' }}</p>
@if (!isset($account))<p><a href="{{ $user->is_platform_admin ? route('admin.dashboard') : route('app') }}">Voltar</a></p>@endif
@endsection
