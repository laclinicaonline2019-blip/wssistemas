<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Cadeia de integridade da auditoria (tamper-evidence).
 *
 * Cada registro guarda o hash do anterior (mesmo escopo/empresa) e um
 * HMAC-SHA256 do seu conteúdo com a APP_KEY. Alterar, remover ou inserir
 * registros fora da aplicação quebra a cadeia. Complementa (ou substitui, em
 * hospedagem compartilhada sem privilégio para triggers) o bloqueio no banco.
 *
 * Limitação: quem tiver ao mesmo tempo acesso ao banco E à APP_KEY consegue
 * recalcular a cadeia — por isso o hash do topo deve ser exportado
 * periodicamente para fora do servidor (ver docs/SECURITY.md).
 */
class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const HASHED_FIELDS = [
        'company_id', 'branch_id', 'user_id', 'actor_type', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'result', 'ip_address', 'user_agent', 'request_id', 'metadata', 'created_at',
    ];

    /** Insere o registro encadeado. Serializa por escopo com lock na linha do topo da cadeia. */
    public function append(array $row): void
    {
        DB::transaction(function () use ($row) {
            $scope = $row['company_id'] ?? 'platform';

            DB::table('audit_chain_heads')->insertOrIgnore(['scope' => $scope, 'last_hash' => self::GENESIS, 'updated_at' => now()]);
            $head = DB::table('audit_chain_heads')->where('scope', $scope)->lockForUpdate()->first();

            $row['prev_hash'] = $head->last_hash;
            $row['hash'] = $this->hash($row, $head->last_hash);
            $id = DB::table('audit_logs')->insertGetId($row);

            DB::table('audit_chain_heads')->where('scope', $scope)
                ->update(['last_id' => $id, 'last_hash' => $row['hash'], 'updated_at' => now()]);
        });
    }

    /**
     * Verifica a cadeia de um escopo.
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, reason: string|null}
     */
    public function verify(string $scope): array
    {
        $prev = self::GENESIS;
        $checked = 0;
        $query = DB::table('audit_logs')->orderBy('id');
        $scope === 'platform' ? $query->whereNull('company_id') : $query->where('company_id', $scope);

        foreach ($query->lazyById(500) as $row) {
            $checked++;
            $data = (array) $row;

            if ($row->prev_hash !== $prev) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $row->id, 'reason' => 'registro anterior removido ou inserido fora da aplicação'];
            }

            if (! hash_equals($this->hash($data, $prev), (string) $row->hash)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $row->id, 'reason' => 'conteúdo do registro alterado'];
            }

            $prev = $row->hash;
        }

        $head = DB::table('audit_chain_heads')->where('scope', $scope)->value('last_hash');

        if ($head !== null && $head !== $prev) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => null, 'reason' => 'registros finais removidos'];
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return DB::table('audit_chain_heads')->orderBy('scope')->pluck('scope')->all();
    }

    private function hash(array $row, string $prev): string
    {
        $payload = [];

        foreach (self::HASHED_FIELDS as $field) {
            $value = $row[$field] ?? null;

            if (in_array($field, ['old_values', 'new_values', 'metadata'], true) && $value !== null) {
                // O banco pode reordenar/reformatar JSON: normaliza antes de assinar.
                $value = $this->canonicalJson(is_string($value) ? json_decode($value, true) : $value);
            } elseif ($field === 'created_at' && $value !== null) {
                $value = substr((string) (is_object($value) ? $value->format('Y-m-d H:i:s') : $value), 0, 19);
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            $payload[$field] = $value;
        }

        return hash_hmac('sha256', $prev.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->key());
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function key(): string
    {
        return 'audit-chain|'.config('app.key');
    }
}
