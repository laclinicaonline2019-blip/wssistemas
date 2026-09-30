@php
    $user = auth()->user();
    $ctx = app(\App\Core\Tenancy\TenantContext::class);
    $isPlatform = $user->is_super_admin;
    $can = fn (string $p) => ! $isPlatform && $user->hasPermission($p);
    // $nav('rota', 'padrão-ativo', 'ícone', 'rótulo', visível)
    $nav = function (string $route, string|array $pattern, string $icon, string $label, bool $visible = true) {
        if (! $visible) return '';
        $active = request()->routeIs(...(array) $pattern);
        return '<li><a'.($active ? ' class="is-active" aria-current="page"' : '').' href="'.route($route).'"><svg><use href="#i-'.$icon.'"/></svg>'.e($label).'</a></li>';
    };
    $soon = fn (string $icon, string $label, string $phase) => '<li><a class="is-disabled" aria-disabled="true"><svg><use href="#i-'.$icon.'"/></svg>'.e($label).'<span class="soon">'.e($phase).'</span></a></li>';
@endphp
<!doctype html>
<html lang="pt-BR">
<head>@include('partials.head')</head>
<body>
@include('partials.icons')
<div class="app">
    <aside class="sidebar" aria-label="Navegação principal">
        <div class="sidebar__brand">
            <img class="icon" src="{{ asset('assets/img/icon-64.png') }}" alt="" width="34" height="34">
            <img class="wordmark" src="{{ asset('assets/img/wordmark.webp') }}" alt="{{ config('aivexa.brand.name') }}" width="170" height="22">
        </div>

        @if ($isPlatform)
            <div class="sidebar__section">Plataforma</div>
            <ul class="nav">
                {!! $nav('platform.dashboard', 'platform.dashboard', 'chart', 'Visão geral') !!}
                {!! $nav('platform.companies.index', 'platform.companies.*', 'building', 'Empresas') !!}
                {!! $nav('platform.plans.index', 'platform.plans.*', 'layers', 'Planos SaaS') !!}
            </ul>
        @else
            <div class="sidebar__section">Clínica</div>
            <ul class="nav">
                {!! $nav('home', 'home', 'home', 'Dashboard', $can('dashboard.visualizar')) !!}
                {!! $nav('patients.index', 'patients.*', 'patient', 'Pacientes', $can('paciente.visualizar')) !!}
                {!! $nav('agenda.index', ['agenda.index', 'agenda.create', 'agenda.show'], 'calendar', 'Agenda', $can('agenda.visualizar')) !!}
                {!! $nav('queue.index', 'queue.*', 'list', 'Fila e senhas', $can('fila.visualizar')) !!}
                {!! $nav('doctors.index', 'doctors.*', 'stethoscope', 'Médicos', $can('medico.visualizar')) !!}
                {!! $nav('specialties.index', 'specialties.*', 'tag', 'Especialidades', $can('medico.visualizar')) !!}
                {!! $soon('heart', 'Atendimento', 'Fase 5') !!}
                {!! $soon('file', 'Documentos', 'Fase 6') !!}
                {!! $soon('cash', 'Financeiro', 'Fase 7') !!}
                {!! $soon('chat', 'Atendimento IA', 'Fase 12') !!}
            </ul>
            @php
                $adminNav = implode('', [
                    $nav('branches.index', 'branches.*', 'building', 'Filiais', $can('filial.visualizar')),
                    $nav('rooms.index', 'rooms.*', 'layers', 'Salas', $can('agenda.configurar')),
                    $nav('agenda.holidays', 'agenda.holidays', 'calendar', 'Feriados e bloqueios', $can('agenda.configurar')),
                    $nav('users.index', 'users.*', 'users', 'Usuários', $can('usuario.visualizar')),
                    $nav('roles.index', 'roles.*', 'shield', 'Perfis de acesso', $can('perfil.visualizar')),
                    $nav('audit.index', 'audit.*', 'list', 'Auditoria', $can('auditoria.visualizar')),
                    $nav('company.edit', 'company.*', 'settings', 'Configurações', $can('empresa.visualizar'))
                ]);
            @endphp
            @if ($adminNav !== '')
                <div class="sidebar__section">Administração</div>
                <ul class="nav">{!! $adminNav !!}</ul>
            @endif
        @endif

        <div class="sidebar__footer">
            {{ config('aivexa.brand.name') }} · v{{ config('app.version', '0.5.0') }}<br>
            Ambiente: {{ config('aivexa.stage') }}
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-ghost btn-sm topbar__toggle" type="button" data-nav-toggle aria-label="Abrir menu"><svg><use href="#i-menu"/></svg></button>

            @if (! $isPlatform && $ctx->hasCompany())
                @php
                    $branches = \App\Modules\Organization\Models\Branch::query()->active()->accessible($ctx->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(['id', 'name']);
                @endphp
                <form method="post" action="{{ route('context.branch') }}" class="row">
                    @csrf
                    <label class="sr-only" for="ctx-branch">Filial de trabalho</label>
                    <select id="ctx-branch" name="branch_id" class="input input-branch" data-autosubmit>
                        @if ($ctx->allowedBranchIds() === null)
                            <option value="">Todas as filiais (consolidado)</option>
                        @endif
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected($ctx->branchId() === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </form>
            @elseif ($isPlatform)
                <span class="badge badge-primary">Super Admin</span>
            @endif

            @if (! $isPlatform && $ctx->hasCompany())
                <form method="get" action="{{ route('search') }}" class="topbar__search" role="search">
                    <label class="sr-only" for="global-q">Buscar</label>
                    <input id="global-q" name="q" class="input" type="search" placeholder="Buscar paciente, CPF, telefone, médico…" value="{{ request()->routeIs('search') ? request('q') : '' }}" autocomplete="off">
                </form>
            @endif

            <div class="topbar__spacer"></div>

            @if (config('aivexa.stage') !== 'production')
                <span class="stage-ribbon hide-sm" title="Dados e integrações deste ambiente não são de produção">{{ config('aivexa.stage') }}</span>
            @endif

            <div class="dropdown">
                <button class="btn btn-ghost btn-sm" type="button" data-dropdown-toggle aria-haspopup="true" aria-expanded="false">
                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                    <span class="hide-sm">{{ \Illuminate\Support\Str::limit($user->name, 22) }}</span>
                </button>
                <div class="dropdown__menu" role="menu">
                    <div class="small muted dropdown__label">{{ $user->email }}</div>
                    <a href="{{ route('account.security') }}" role="menuitem">Minha conta e segurança</a>
                    <button type="button" data-theme-set="light" role="menuitem">Tema claro</button>
                    <button type="button" data-theme-set="dark" role="menuitem">Tema escuro</button>
                    <button type="button" data-theme-set="system" role="menuitem">Tema do sistema</button>
                    <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" role="menuitem">Sair</button></form>
                </div>
            </div>
        </header>

        <main class="content" id="conteudo">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
