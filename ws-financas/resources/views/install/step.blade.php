@extends('install.layout')
@section('content')
@php($keys = array_keys($steps))
<ol class="list order" style="padding:0">
  @foreach ($steps as $k => $label)
    <li style="grid-template-columns:28px minmax(0,1fr) auto"><span class="step" @if($k===$step) style="background:var(--accent);color:var(--accent-ink)" @endif>{{ $loop->iteration }}</span>
      <span class="{{ $k === $step ? '' : 'muted' }}" style="font-weight:{{ $k === $step ? 700 : 500 }}">{{ $label }}</span>
      @if (in_array($k, $done, true))<a class="small" href="/instalar?etapa={{ $k }}">✓ refazer</a>@endif</li>
  @endforeach
</ol>
@isset($stepError)<div class="banner bad" role="alert">{{ $stepError }}</div>@endisset

<form class="card" method="post" action="/instalar/{{ $step }}">
  <input type="hidden" name="_csrf" value="{{ $csrf }}">
  <h2>{{ $steps[$step] }}</h2>

  @if ($step === 'requisitos')
    <div class="table-wrap"><table class="t"><tbody>
    @foreach ($checks as $c)
      <tr><td><span class="pill {{ $c['ok'] ? 'ok' : ($c['level'] === 'blocker' ? 'bad' : 'warn') }}">{{ $c['ok'] ? 'OK' : ($c['level'] === 'blocker' ? 'Obrigatório' : ($c['level'] === 'info' ? 'Info' : 'Atenção')) }}</span> {{ $c['item'] }}</td><td class="small muted">{{ $c['info'] }}</td></tr>
    @endforeach
    </tbody></table></div>
    <p class="small muted mt0">Ao continuar o instalador cria as pastas de armazenamento, ajusta as permissões (755 pastas, 600 no .env), protege as pastas internas com .htaccess e limpa caches antigos.</p>
    <button class="btn primary" type="submit">Preparar pastas e continuar</button>

  @elseif ($step === 'chaves')
    @if ($hasKey)
      <p class="mt0"><span class="pill ok">APP_KEY já existe</span> Ela será <b>mantida</b> — trocar a chave tornaria ilegíveis os dados criptografados já gravados.</p>
    @else
      <p class="mt0">O .env ainda não tem APP_KEY. O instalador vai gerar uma chave aleatória forte (256 bits) e gravá-la no .env — sem terminal.</p>
    @endif
    <p class="small muted mt0">Também são gerados, se ainda não existirem: chave da auditoria (AUDIT_HMAC_KEY), senha dos backups (BACKUP_PASSWORD) e os tokens dos webhooks do WhatsApp e do Asaas. Eles ficam só no .env. <b>Depois da instalação, baixe uma cópia do .env pelo Gerenciador de Arquivos e guarde em local seguro</b>: sem a APP_KEY e a BACKUP_PASSWORD não é possível ler backups e dados criptografados.</p>
    <button class="btn primary" type="submit">{{ $hasKey ? 'Manter a chave e gerar o que falta' : 'Gerar APP_KEY e continuar' }}</button>

  @elseif ($step === 'banco')
    <p class="mt0 small muted">No cPanel → Bancos de dados MySQL: o banco e o usuário têm o prefixo da conta (ex.: wand5158_…), e o usuário precisa estar adicionado ao banco com <b>TODOS OS PRIVILÉGIOS</b>.</p>
    <div class="form">
      <div class="field"><label for="h">Servidor</label><input id="h" name="db_host" required value="{{ $env['DB_HOST'] ?? 'localhost' }}"></div>
      <div class="field"><label for="p">Porta</label><input id="p" name="db_port" required inputmode="numeric" value="{{ $env['DB_PORT'] ?? '3306' }}"></div>
      <div class="field"><label for="d">Nome do banco</label><input id="d" name="db_database" required value="{{ $env['DB_DATABASE'] ?? '' }}" autocomplete="off"></div>
      <div class="field"><label for="u">Usuário</label><input id="u" name="db_username" required value="{{ $env['DB_USERNAME'] ?? '' }}" autocomplete="off"></div>
      <div class="field full"><label for="s">Senha do banco</label><input id="s" name="db_password" type="password" autocomplete="new-password" placeholder="{{ !empty($env['DB_PASSWORD']) ? 'deixe em branco para manter a senha já gravada no .env' : '' }}"></div>
      @if (!empty($env['DB_PASSWORD']))<label class="inline-check full"><input type="checkbox" name="keep_password" value="1" checked> Manter a senha do .env se o campo ficar em branco</label>@endif
    </div>
    <p class="small muted mt0">Teste feito: conexão, versão (MySQL 5.7.8+ / MariaDB 10.3+) e privilégios (criar, alterar e apagar tabelas de teste). Dados existentes nunca são apagados.</p>
    <button class="btn primary" type="submit">Testar conexão e salvar</button>

  @elseif ($step === 'aplicacao')
    <div class="form">
      <div class="field full"><label for="url">Endereço do sistema</label><input id="url" name="app_url" required value="{{ $url }}"></div>
      <div class="field full"><label for="st">Ambiente</label><select id="st" name="stage">
        <option value="homologation" @selected($stage==='homologation')>Homologação (testes — faixa amarela, use dados fictícios)</option>
        <option value="production" @selected($stage==='production')>Produção</option></select></div>
    </div>
    <p class="small muted mt0">Grava APP_URL, APP_ENV=production, APP_DEBUG=false (erros não aparecem para o público), cookies só por HTTPS e sessão/cache/fila no banco (compatível com hospedagem compartilhada).</p>
    <button class="btn primary" type="submit">Salvar e continuar</button>

  @elseif ($step === 'migracoes')
    @isset($status)
      <p class="mt0">Já aplicadas: <b>{{ $status['ran'] }}</b> · Pendentes: <b>{{ count($status['pending']) }}</b></p>
      @if ($status['pending'])<ul class="small">@foreach ($status['pending'] as $m)<li><code>{{ $m }}</code></li>@endforeach</ul>@endif
    @endisset
    @if (!empty($output))<pre class="small" style="white-space:pre-wrap;background:var(--sunk);padding:10px;border-radius:10px;max-height:240px;overflow:auto">{{ $output }}</pre>@endif
    <p class="small muted mt0">Executa somente as migrações do próprio sistema (lista acima), uma vez, com trava contra execução dupla. Tabelas e dados existentes são preservados. Também cadastra os planos padrão, se ainda não existirem.</p>
    <button class="btn primary" type="submit">{{ isset($status) && !$status['pending'] ? 'Conferir e continuar' : 'Criar/atualizar as tabelas' }}</button>

  @elseif ($step === 'proprietario')
    @if ($owner ?? null)
      <p class="mt0"><span class="pill ok">Proprietário já cadastrado</span> {{ $owner }}. Por segurança o instalador não cria outro administrador nem altera senhas — os dados existentes foram preservados.</p>
      <button class="btn primary" type="submit">Continuar</button>
    @else
      <div class="form">
        <div class="field"><label for="n">Nome</label><input id="n" name="name" required autocomplete="name"></div>
        <div class="field"><label for="e">E-mail (login)</label><input id="e" name="email" type="email" required autocomplete="username"></div>
        <div class="field"><label for="pw">Senha</label><input id="pw" name="password" type="password" required autocomplete="new-password"></div>
        <div class="field"><label for="pw2">Repita a senha</label><input id="pw2" name="password_confirmation" type="password" required autocomplete="new-password"></div>
      </div>
      <p class="small muted mt0">Mínimo de 10 caracteres com maiúsculas, minúsculas, números e símbolos. No primeiro acesso será exigida a verificação em duas etapas.</p>
      <button class="btn primary" type="submit">Criar administrador principal</button>
    @endif

  @else
    <p class="mt0">Ao concluir, o instalador é <b>bloqueado definitivamente</b> (arquivo <code>storage/app/installed</code> + <code>WSF_INSTALLER_LOCKED=true</code> no .env), o código e a sessão de instalação são apagados e a página /instalar passa a responder "não encontrada".</p>
    <button class="btn primary" type="submit">Concluir instalação</button>
  @endif
</form>
@endsection
