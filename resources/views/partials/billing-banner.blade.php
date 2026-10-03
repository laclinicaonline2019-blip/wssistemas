@php
    $bUser = auth()->user();
    $bSub = $bUser && ! $bUser->is_super_admin && $bUser->company_id ? \App\Modules\Billing\Models\Subscription::query()->where('company_id', $bUser->company_id)->first() : null;
    $bCan = $bSub && app(\App\Core\Tenancy\TenantContext::class)->companyIdOrNull() && $bUser->hasPermission('assinatura.gerenciar');
    $bTrialDays = $bSub?->status === 'trialing' && $bSub->trial_ends_on ? (int) \Carbon\CarbonImmutable::parse(now('America/Sao_Paulo')->toDateString())->diffInDays(\Carbon\CarbonImmutable::parse($bSub->trial_ends_on->toDateString()), false) : null;
@endphp
@if ($bSub && in_array($bSub->status, ['past_due', 'suspended'], true))
    <div class="alert {{ $bSub->status === 'suspended' ? 'alert-error' : 'alert-warning' }}">
        <strong>{{ $bSub->statusLabel() }}.</strong>
        @if ($bSub->status === 'suspended') O acesso da clínica está bloqueado até a confirmação do pagamento. Nenhum dado foi apagado.
        @else Há fatura vencida da assinatura. O acesso será bloqueado se não for paga em até {{ config('billing.suspend_after_days') }} dias após o vencimento. @endif
        @if ($bCan && ! request()->routeIs('billing.*')) <a href="{{ route('billing.index') }}">Regularizar agora</a>@endif
    </div>
@elseif ($bTrialDays !== null && $bTrialDays <= 7 && $bCan)
    <div class="alert alert-info">Seu teste grátis termina {{ $bTrialDays <= 0 ? 'hoje' : 'em '.$bTrialDays.' dia(s)' }}. @unless (request()->routeIs('billing.*'))<a href="{{ route('billing.index') }}">Escolha o plano</a> para continuar sem interrupção.@endunless</div>
@endif
