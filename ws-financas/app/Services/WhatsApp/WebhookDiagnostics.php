<?php

namespace App\Services\WhatsApp;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Diagnóstico do webhook do WhatsApp — arquivo próprio storage/logs/whatsapp-AAAA-MM-DD.log.
 *
 * Registra SÓ as etapas (chegou ao Laravel, assinatura, mensagens no corpo, gravação, processamento, resposta, status da Meta).
 * Nunca registra: texto das mensagens, telefone, wamid (ele contém o telefone codificado), tokens ou App Secret.
 * Identificadores aparecem apenas como resumo curto (8 caracteres de um HMAC), suficientes para correlacionar eventos.
 */
final class WebhookDiagnostics
{
    private static ?string $requestId = null;

    public static function begin(): string
    {
        return self::$requestId = substr(bin2hex(random_bytes(6)), 0, 10);
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    /** Resumo não reversível de um identificador (wamid, telefone). */
    public static function ref(?string $value): ?string
    {
        return $value === null || $value === '' ? null : substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 8);
    }

    public static function info(string $stage, array $context = []): void
    {
        self::write('info', $stage, $context);
    }

    public static function warning(string $stage, array $context = []): void
    {
        self::write('warning', $stage, $context);
    }

    public static function error(string $stage, array $context = []): void
    {
        self::write('error', $stage, $context);
    }

    /** Erro sem dados privados: classe, código SQLSTATE/driver e local — nunca a mensagem completa (pode conter valores). */
    public static function exception(\Throwable $e): array
    {
        $out = ['erro' => class_basename($e), 'local' => basename($e->getFile()).':'.$e->getLine()];
        if ($e instanceof QueryException || $e instanceof \PDOException) {
            $info = $e->errorInfo ?? [];
            $out['sqlstate'] = $info[0] ?? (string) $e->getCode();
            $out['codigo_driver'] = $info[1] ?? null;
        }

        return $out;
    }

    private static function write(string $level, string $stage, array $context): void
    {
        try {
            Log::channel('whatsapp')->{$level}('webhook.'.$stage, ['req' => self::$requestId] + $context);
        } catch (\Throwable) {
            // diagnóstico nunca pode derrubar o atendimento
        }
    }

    /** Últimos eventos do arquivo de diagnóstico (para o painel do proprietário). @return list<array{quando:string, nivel:string, etapa:string, dados:string}> */
    public static function recent(int $limit = 40): array
    {
        $files = glob(storage_path('logs/whatsapp-*.log')) ?: [];
        rsort($files);
        $lines = [];
        foreach ($files as $f) {
            $content = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_merge(array_reverse($content), $lines === [] ? [] : $lines);
            if (count($lines) >= $limit) {
                break;
            }
        }
        $out = [];
        foreach (array_slice($lines, 0, $limit) as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+\w+\.(\w+):\s+webhook\.(\S+)\s*(.*)$/', $line, $m)) {
                $out[] = ['quando' => substr($m[1], 0, 19), 'nivel' => strtolower($m[2]), 'etapa' => $m[3], 'dados' => trim($m[4])];
            }
        }

        return $out;
    }
}
