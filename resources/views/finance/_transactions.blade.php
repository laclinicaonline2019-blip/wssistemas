{{-- Movimentações com estorno e recibo. $transactions, $showOrigin (bool) --}}
@php use App\Core\Support\Format; $me = auth()->user(); $tz = 'America/Sao_Paulo'; @endphp
<div class="table-wrap"><table class="table">
    <thead><tr><th>Data</th><th>Movimento</th><th>Forma</th><th class="t-right">Valor</th><th class="hide-sm">Usuário</th><th></th></tr></thead>
    <tbody>
    @forelse ($transactions as $t)
        @php $reversed = $t->reversal !== null; @endphp
        <tr class="{{ $reversed || $t->kind === 'reversal' ? 'muted' : '' }}">
            <td class="small nowrap">{{ $t->occurred_at->timezone($tz)->format('d/m/Y H:i') }}</td>
            <td>{{ \App\Modules\Finance\Models\FinancialTransaction::KINDS[$t->kind] }}
                @if ($showOrigin ?? false)
                    <div class="small muted">{{ $t->receivable?->patient?->displayName() ?? $t->payable?->supplier ?? $t->description }}</div>
                @elseif ($t->description && in_array($t->kind, ['withdrawal', 'deposit', 'reversal'], true))
                    <div class="small muted">{{ $t->description }}</div>
                @endif
                @if ($reversed)<span class="badge badge-danger">estornado</span>@endif</td>
            <td class="small">{{ $t->methodLabel() }}{{ $t->card_installments > 1 ? ' '.$t->card_installments.'x' : '' }}{{ $t->authorization_code ? ' · '.$t->authorization_code : '' }}</td>
            <td class="t-right nowrap {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ Format::money($t->signedCents()) }}</td>
            <td class="hide-sm small">{{ $t->creator?->name }}</td>
            <td class="actions"><div class="row">
                @if ($t->kind === 'receipt')
                    <a class="btn btn-sm btn-ghost" href="{{ route('transactions.receipt', $t) }}" target="_blank" rel="noopener" aria-label="Imprimir recibo"><svg><use href="#i-printer"/></svg></a>
                @endif
                @if (! $reversed && $t->kind !== 'reversal' && $me->hasPermission('pagamento.estornar'))
                    <details class="inline-details"><summary class="btn btn-sm btn-ghost">Estornar</summary>
                        <form method="post" action="{{ route('transactions.reverse', $t) }}" class="stack mt-1" data-confirm="Confirmar o estorno de {{ Format::money($t->amount_cents) }}?">
                            @csrf
                            <input name="reason" class="input input-sm" minlength="10" maxlength="230" required placeholder="Motivo do estorno">
                            <button class="btn btn-sm btn-danger" type="submit">Confirmar estorno</button>
                        </form>
                    </details>
                @endif
            </div></td>
        </tr>
    @empty
        <tr><td colspan="6" class="empty">Nenhuma movimentação.</td></tr>
    @endforelse
    </tbody>
</table></div>
