<?php

namespace App\Modules\Banking\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Parsers\ParsedStatement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Open Finance por agregador (Pluggy). A clínica conecta o banco no painel/Connect da Pluggy e
 * informa aqui o Client ID/Secret e o ID da conta. O sistema só LÊ as transações:
 * POST /auth {clientId, clientSecret} → apiKey; GET /transactions?accountId&from&to&page (X-API-KEY).
 * Integração a homologar com credenciais reais do agregador antes de produção.
 */
class PluggyClient
{
    public const BASE = 'https://api.pluggy.ai';

    public function fetch(BankAccount $account, string $from, string $to): ParsedStatement
    {
        $id = (string) $account->credential('client_id');
        $secret = (string) $account->credential('client_secret');
        if ($id === '' || $secret === '' || ! $account->external_account_id) {
            throw new BusinessRuleViolation('Open Finance não configurado: informe Client ID, Client Secret e o ID da conta na Pluggy.', 'openfinance_config');
        }

        try {
            $auth = Http::acceptJson()->asJson()->timeout(20)->post(self::BASE.'/auth', ['clientId' => $id, 'clientSecret' => $secret]);
            $key = (string) $auth->json('apiKey');
            if (! $auth->successful() || $key === '') {
                throw new BusinessRuleViolation('Pluggy recusou as credenciais ('.$auth->status().').', 'openfinance_auth');
            }

            $lines = [];
            $page = 1;
            do {
                $r = Http::acceptJson()->withHeaders(['X-API-KEY' => $key])->timeout(30)->get(self::BASE.'/transactions', [
                    'accountId' => $account->external_account_id, 'from' => $from, 'to' => $to, 'pageSize' => 500, 'page' => $page,
                ]);
                if (! $r->successful()) {
                    throw new BusinessRuleViolation('Pluggy: falha ao ler transações ('.$r->status().').', 'openfinance_fetch');
                }
                foreach ((array) $r->json('results') as $t) {
                    $value = (int) round(abs((float) ($t['amount'] ?? 0)) * 100);
                    if ($value === 0 || empty($t['id']) || empty($t['date'])) {
                        continue;
                    }
                    $debit = isset($t['type']) ? strtoupper((string) $t['type']) === 'DEBIT' : (float) $t['amount'] < 0;
                    $lines[] = ['date' => substr((string) $t['date'], 0, 10), 'amount_cents' => $debit ? -$value : $value,
                        'description' => mb_substr(trim((string) ($t['description'] ?? 'Lançamento')), 0, 255), 'reference' => 'pluggy:'.$t['id']];
                }
                $pages = max(1, (int) $r->json('totalPages', 1));
            } while (++$page <= min($pages, 20));
        } catch (ConnectionException) {
            throw new BusinessRuleViolation('Sem conexão com a Pluggy.', 'openfinance_connection');
        }

        return new ParsedStatement($lines, $from, $to);
    }
}
