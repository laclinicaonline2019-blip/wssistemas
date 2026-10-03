@php use App\Modules\Banking\Models\BankAccount; @endphp
<x-field name="name" label="Nome da conta" col="col-6" :value="$a?->name" required placeholder="Ex.: Itaú — conta movimento" />
<div class="field col-6"><label for="ba-b">Unidade (opcional)</label>
    <select id="ba-b" name="branch_id" class="input"><option value="">Empresa toda</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $a?->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
<x-field name="bank_code" label="Código do banco" col="col-4" :value="$a?->bank_code" inputmode="numeric" placeholder="341" />
<x-field name="agency" label="Agência" col="col-4" :value="$a?->agency" />
<x-field name="account_number" label="Conta" col="col-4" :value="$a?->account_number" />
<div class="field col-12"><label for="ba-s">Como o extrato chega</label>
    <select id="ba-s" name="sync_provider" class="input" data-toggle-group="sync">@foreach (BankAccount::SYNC as $k => $l)<option value="{{ $k }}" @selected(old('sync_provider', $a?->sync_provider ?? 'none') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="col-12 form-grid" data-toggle-show="sync:pluggy">
    <p class="help col-12">Conecte o banco na Pluggy (Open Finance regulado pelo Banco Central — o banco pede o consentimento do titular) e informe aqui as credenciais da API e o ID da conta. O sistema só lê o extrato. <strong>Homologue com sua conta Pluggy antes de usar em produção.</strong></p>
    <x-field name="external_account_id" label="ID da conta na Pluggy" col="col-12" :value="$a?->external_account_id" />
    <x-field name="client_id" label="Client ID" type="password" col="col-6" autocomplete="off" :help="$a?->credential('client_id') ? 'Configurado — deixe vazio para manter.' : null" />
    <x-field name="client_secret" label="Client Secret" type="password" col="col-6" autocomplete="off" :help="$a?->credential('client_secret') ? 'Configurado — deixe vazio para manter.' : null" />
</div>
