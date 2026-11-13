<?php

namespace App\Core\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ancoragem externa diária da cadeia de auditoria.
 *
 * A cadeia sozinha não detecta a exclusão dos ÚLTIMOS registros. A âncora registra {last_id, last_hash, total}
 * assinada por HMAC; uma cópia fica em storage e outra vai por e-mail ao(s) proprietário(s) — fora do servidor.
 * Na verificação, a cadeia atual precisa conter o registro ancorado com o mesmo hash e ao menos o mesmo total.
 * TSA (RFC 3161): desativada nesta versão (WSF_AUDIT_TSA=off).
 */
class AuditAnchor
{
    public function dir(): string
    {
        $d = storage_path('app/auditoria/ancoras');
        if (! is_dir($d)) {
            @mkdir($d, 0700, true);
        }

        return $d;
    }

    /** @return array{anchor: array, file: string, emailed: list<string>} */
    public function create(): array
    {
        $last = AuditLog::query()->orderByDesc('id')->first(['id', 'hash']);
        $anchor = [
            'sistema' => 'WS Finanças em Dia', 'ambiente' => (string) config('wsf.stage'), 'url' => (string) config('app.url'),
            'last_id' => (int) ($last->id ?? 0), 'last_hash' => (string) ($last->hash ?? ''), 'total' => AuditLog::query()->count(),
            'key_id' => (string) config('wsf.security.audit_hmac_key') !== '' ? AuditLogger::keyId() : null,
            'gerada_em' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'tsa' => null,
        ];
        $anchor['assinatura'] = self::sign($anchor);
        $file = $this->dir().'/ancora-'.now()->format('Ymd-His').'.json';
        file_put_contents($file, json_encode($anchor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($file, 0600);

        $emailed = [];
        if (config('wsf.audit.anchor_email')) {
            $to = array_filter(array_map('trim', explode(',', (string) config('wsf.audit.anchor_recipients'))));
            if (! $to) {
                $to = User::query()->where('is_platform_admin', true)->where('status', 'active')->pluck('email')->all();
            }
            foreach ($to as $email) {
                try {
                    Mail::raw($this->emailBody($anchor), function ($m) use ($email, $anchor) {
                        $m->to($email)->subject('Âncora diária da auditoria — '.config('wsf.brand.name').' — registro #'.$anchor['last_id']);
                    });
                    $emailed[] = $email;
                } catch (\Throwable $e) {
                    Log::error('Âncora de auditoria: falha ao enviar e-mail', ['erro' => $e->getMessage()]);
                }
            }
        }

        return ['anchor' => $anchor, 'file' => $file, 'emailed' => $emailed];
    }

    /** Última âncora local com assinatura válida. */
    public function latestValid(): ?array
    {
        $files = glob($this->dir().'/ancora-*.json') ?: [];
        rsort($files);
        foreach ($files as $f) {
            $a = json_decode((string) file_get_contents($f), true);
            if (is_array($a) && self::valid($a)) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Confere uma âncora recebida por e-mail (colada no painel) contra a cadeia atual.
     *
     * @return array{valid_signature: bool, present: bool, total_ok: bool, message: string}
     */
    public function checkExternal(string $json): array
    {
        $a = json_decode(trim($json), true);
        if (! is_array($a) || ! isset($a['last_id'], $a['last_hash'], $a['total'], $a['assinatura'])) {
            return ['valid_signature' => false, 'present' => false, 'total_ok' => false, 'message' => 'Conteúdo não é uma âncora do WS Finanças.'];
        }
        $sig = self::valid($a);
        $at = AuditLog::query()->where('id', (int) $a['last_id'])->value('hash');
        $present = $at !== null && hash_equals((string) $a['last_hash'], (string) $at);
        $totalOk = AuditLog::query()->count() >= (int) $a['total'];
        $msg = ! $sig ? 'Assinatura da âncora inválida (alterada ou de outra chave).'
            : (! $present ? 'ATENÇÃO: o registro ancorado #'.$a['last_id'].' não existe mais ou foi alterado.'
                : (! $totalOk ? 'ATENÇÃO: há menos registros do que na data da âncora (exclusão do final).' : 'Âncora confere: nenhum registro anterior a ela foi removido ou alterado.'));

        return ['valid_signature' => $sig, 'present' => $present, 'total_ok' => $totalOk, 'message' => $msg];
    }

    public static function sign(array $anchor): string
    {
        unset($anchor['assinatura']);

        return hash_hmac('sha256', json_encode($anchor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), AuditLogger::legacyKey());
    }

    public static function valid(array $anchor): bool
    {
        return isset($anchor['assinatura']) && hash_equals(self::sign($anchor), (string) $anchor['assinatura']);
    }

    private function emailBody(array $anchor): string
    {
        return 'Âncora diária da trilha de auditoria do '.config('wsf.brand.name').".\n\n"
            ."Guarde este e-mail: ele permite provar, no futuro, que nenhum registro anterior a esta data foi removido.\n"
            ."Para conferir: Proprietário → Sistema e backups → Auditoria → Conferir âncora recebida por e-mail (cole o bloco abaixo).\n\n"
            ."-----BEGIN WSF ANCORA-----\n".json_encode($anchor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n-----END WSF ANCORA-----\n";
    }
}
