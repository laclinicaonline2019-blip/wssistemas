<?php

namespace App\Core\Audit;

use App\Models\Plan;

/**
 * Compatibilidade dos registros v1 (formato 1.0.0/1.1.0) sem alterar nada.
 *
 * O MySQL (tipo JSON nativo) devolve as chaves dos objetos em outra ordem; o HMAC v1 foi calculado sobre o
 * json_encode na ORDEM ORIGINAL. A verificação procura essa ordem e só aceita o registro se reproduzir
 * exatamente o HMAC gravado (comparação em tempo constante) — impossível sem a chave secreta.
 *
 * Ordem de tentativa por objeto: (1) ordem lida; (2) ordem conhecida do código (todos os tipos de registro
 * gravados pela 1.0.0/1.1.0); (3) todas as permutações, só para objetos de até 5 chaves (inclui a forma de
 * objeto de listas com chaves numéricas). Limite de tentativas por registro: esgotado = "não verificável".
 */
final class LegacyVerifier
{
    public const MAX_PERMUTATION_KEYS = 5;

    /**
     * Ordens em que o código 1.0.0/1.1.0 grava os campos de cada tipo de registro (extraídas do código-fonte).
     * Serve só para PRIORIZAR tentativas: a decisão é sempre do HMAC.
     */
    public const KNOWN_ORDERS = [
        ['tipo', 'desc', 'valor', 'repetir', 'modo', 'origem'],                      // lancamento.criar
        ['antes', 'depois'],                                                         // lancamento.editar / plano.editar
        ['description', 'amount_cents', 'due_date', 'category', 'paid'],             // lancamento.editar (antes/depois)
        ['desc', 'valor', 'escopo', 'qtd'],                                          // lancamento.excluir
        ['credor', 'saldo'],                                                         // divida.salvar
        ['arquivo', 'bytes'],                                                        // backup.criar
        ['arquivo', 'ok', 'problemas'],                                              // backup.teste_restauracao
        ['email', 'papel', 'empresas'],                                              // usuario.vincular
        ['papel', 'empresas', 'status'],                                             // usuario.permissoes
        ['despesas', 'receitas', 'dividas', 'config', 'ja_existentes', 'exemplos_ignorados', 'invalidos'], // dados.importar_local
        ['de', 'para', 'sha256', 'assinado'],                                        // atualizacao.iniciar
        ['de', 'para'],                                                              // atualizacao.concluir
        ['de', 'para', 'etapa', 'erro', 'situacao'],                                 // atualizacao.falhou
        ['de', 'para', 'modo'],                                                      // atualizacao.reverter
        ['plano', 'dias'],                                                           // assinatura.teste_iniciado
        ['plano', 'ciclo', 'valor'],                                                 // assinatura.assinatura_confirmada
        ['de', 'para', 'ciclo'],                                                     // assinatura.plano_alterado
        ['fatura', 'valor'],                                                         // assinatura.pagamento_*
        ['motivo', 'exclusao_em'],                                                   // assinatura.cancelada
        ['code', 'price_monthly_cents', 'price_yearly_cents'],                       // plano.criar
        ['price_monthly_cents', 'price_yearly_cents', 'max_companies', 'max_users', 'features', 'active'], // plano.editar
        ['backup', 'saida'],                                                         // plataforma.migrar
        ['trial_days', 'grace_days', 'retention'],                                   // plataforma.configuracoes
        ['nome', 'ativa'],                                                           // empresa.editar
        ['formato', 'de', 'ate', 'empresas'],                                        // relatorio.exportar
    ];

    private int $attempts = 0;

    public function __construct(private readonly int $maxAttempts = 50000) {}

    /**
     * @param  array  $row  atributos do registro (data como array associativo, created_at 'Y-m-d H:i:s')
     * @return array{ok: bool, method: string, attempts: int, exhausted: bool}
     */
    public function verify(array $row, string $storedHash): array
    {
        $this->attempts = 0;
        $data = $row['data'];

        if ($this->check($row, $data, $storedHash)) {
            return ['ok' => true, 'method' => 'ordem lida', 'attempts' => $this->attempts, 'exhausted' => false];
        }
        if (! is_array($data)) {
            return ['ok' => false, 'method' => '', 'attempts' => $this->attempts, 'exhausted' => false];
        }
        foreach ($this->variants($data) as [$candidate, $how]) {
            if ($this->attempts >= $this->maxAttempts) {
                return ['ok' => false, 'method' => '', 'attempts' => $this->attempts, 'exhausted' => true];
            }
            if ($candidate === $data && $how === 'ordem lida') {
                continue;
            }
            if ($this->check($row, $candidate, $storedHash)) {
                return ['ok' => true, 'method' => $how, 'attempts' => $this->attempts, 'exhausted' => false];
            }
        }

        return ['ok' => false, 'method' => '', 'attempts' => $this->attempts, 'exhausted' => $this->attempts >= $this->maxAttempts];
    }

    private function check(array $row, mixed $data, string $storedHash): bool
    {
        $this->attempts++;
        $row['data'] = $data;

        return hash_equals($storedHash, AuditLogger::hashOf($row));
    }

    /**
     * Variações do valor (gerador preguiçoso, em ordem de prioridade). Cada item: [valor, descrição do método].
     *
     * @return \Generator<array{0: mixed, 1: string}>
     */
    private function variants(mixed $v, int $depth = 0): \Generator
    {
        if (! is_array($v) || $v === [] || $depth > 8) {
            yield [$v, 'ordem lida'];

            return;
        }
        $keys = array_keys($v);
        foreach ($this->keyOrders($keys) as [$order, $how]) {
            // combina a ordem deste nível com as variações de cada filho (produto cartesiano preguiçoso)
            foreach ($this->product($order, $v, $depth) as [$assembled, $childHow]) {
                $label = $how;
                if ($childHow !== '' && $childHow !== 'ordem lida') {
                    $label = ($how === 'ordem lida' ? '' : $how.'; ').$childHow;
                }
                yield [$assembled, $label];
            }
        }
    }

    /** @return \Generator<array{0: array, 1: string}> */
    private function product(array $order, array $v, int $depth, int $i = 0, array $acc = [], string $how = ''): \Generator
    {
        if ($i === count($order)) {
            yield [$acc, $how];

            return;
        }
        $k = $order[$i];
        foreach ($this->variants($v[$k], $depth + 1) as [$child, $childHow]) {
            $next = $acc;
            $next[$k] = $child;
            $h = $childHow !== 'ordem lida' ? trim($how.' '.$k.': '.$childHow) : $how;
            yield from $this->product($order, $v, $depth, $i + 1, $next, $h);
        }
    }

    /** @return \Generator<array{0: array, 1: string}> */
    private function keyOrders(array $keys): \Generator
    {
        $seen = [];
        $emit = function (array $order, string $how) use (&$seen) {
            $sig = json_encode($order);
            if (isset($seen[$sig])) {
                return null;
            }
            $seen[$sig] = true;

            return [$order, $how];
        };

        if ($r = $emit($keys, 'ordem lida')) {
            yield $r;
        }
        foreach ($this->knownOrdersFor($keys) as $order) {
            if ($r = $emit($order, 'ordem original do código ['.implode(', ', $order).']')) {
                yield $r;
            }
        }
        if (count($keys) <= self::MAX_PERMUTATION_KEYS) {
            foreach ($this->permutations($keys) as $p) {
                if ($r = $emit($p, 'ordem das chaves ['.implode(', ', $p).']')) {
                    yield $r;
                }
            }
        }
    }

    /** @return list<list<string|int>> */
    private function knownOrdersFor(array $keys): array
    {
        $set = $keys;
        sort($set);
        $out = [];
        foreach (self::KNOWN_ORDERS as $order) {
            $o = $order;
            sort($o);
            if ($o === $set) {
                $out[] = $order;
            }
        }
        // recursos de um plano (array_fill_keys na ordem dos checkboxes = ordem de Plan::FEATURES)
        $features = array_keys(Plan::FEATURES);
        if ($keys && ! array_diff($keys, $features)) {
            $out[] = array_values(array_filter($features, fn ($f) => in_array($f, $keys, true)));
        }

        return $out;
    }

    /** @return \Generator<list<string|int>> */
    private function permutations(array $items): \Generator
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $p) {
                yield array_merge([$item], $p);
            }
        }
    }
}
