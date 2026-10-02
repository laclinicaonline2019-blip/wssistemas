<?php

namespace App\Modules\Portal\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Portal\Mail\PortalAccessMail;
use App\Modules\Portal\Models\PatientAccount;
use App\Modules\Portal\Models\PatientAccountToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Contas do portal do paciente.
 *
 * - Criadas pela clínica (convite): o link de ativação vai por e-mail, WhatsApp ou é
 *   copiado pela recepção. O token só existe no link; no banco fica o SHA-256.
 * - Login por CPF ou e-mail + senha, com rate limit, bloqueio temporário e mensagens
 *   neutras (não revela se o CPF tem conta).
 * - "Esqueci a senha": CPF + data de nascimento → link para o e-mail cadastrado
 *   (resposta sempre neutra).
 */
class PortalAccountService
{
    public const NEUTRAL = 'CPF/e-mail ou senha inválidos.';

    public function __construct(private readonly AuditLogger $audit) {}

    // ------------------------------------------------------------------ clínica

    /**
     * Gera o link de acesso: ativação (conta nova/convidada) ou redefinição (conta ativa).
     *
     * @return array{account: PatientAccount, url: string, purpose: string, expires_at: CarbonImmutable}
     */
    public function issueLink(User $actor, Patient $patient, ?string $email = null): array
    {
        if ($patient->isAnonymized()) {
            throw new BusinessRuleViolation('Paciente anonimizado não pode ter acesso ao portal.', 'patient_anonymized', 409);
        }
        if ($patient->status !== 'active') {
            throw new BusinessRuleViolation('Reative o cadastro do paciente antes de liberar o portal.', 'patient_inactive', 409);
        }

        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;

        return DB::transaction(function () use ($actor, $patient, $email) {
            $account = PatientAccount::query()->where('patient_id', $patient->id)->lockForUpdate()->first();

            if ($account?->status === 'blocked') {
                throw new BusinessRuleViolation('Acesso ao portal bloqueado — desbloqueie antes de gerar um novo link.', 'portal_blocked', 409);
            }
            if ($email && PatientAccount::query()->where('email', $email)->when($account, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
                throw new BusinessRuleViolation('Este e-mail já é usado por outro acesso ao portal.', 'portal_email_taken', 409);
            }

            if (! $account) {
                $account = PatientAccount::create(['patient_id' => $patient->id, 'email' => $email ?? ($patient->email ? mb_strtolower($patient->email) : null), 'created_by' => $actor->id]);
                if ($account->email && PatientAccount::query()->where('email', $account->email)->whereKeyNot($account->id)->exists()) {
                    $account->forceFill(['email' => null])->save();
                }
            } elseif ($email && $email !== $account->email) {
                $account->forceFill(['email' => $email])->save();
            }

            $purpose = $account->isActive() ? 'reset' : 'activation';
            [$token, $expires] = $this->newToken($account, $purpose, $actor->id);

            return ['account' => $account, 'url' => route('portal.activate', ['clinic' => $this->slug($account), 'token' => $token]), 'purpose' => $purpose, 'expires_at' => $expires];
        });
    }

    /** Envia o link por e-mail (sem dados de saúde no corpo). */
    public function sendLink(PatientAccount $account, string $url, string $purpose): void
    {
        if (! $account->email) {
            throw new BusinessRuleViolation('Informe o e-mail do paciente para enviar o link.', 'portal_email_missing');
        }

        $company = Company::query()->findOrFail($account->company_id);
        $patient = $account->patient;
        Mail::to($account->email)->send(new PortalAccessMail(
            $company->trade_name, Str::before($patient->displayName(), ' '), $url, $purpose,
            $purpose === 'activation' ? config('aivexa.portal.activation_ttl_hours').' horas' : config('aivexa.portal.reset_ttl_minutes').' minutos',
        ));
        $this->audit->record('portal.link_emailed', $account, metadata: ['purpose' => $purpose]);
    }

    public function setBlocked(PatientAccount $account, bool $blocked): PatientAccount
    {
        $account->forceFill($blocked
            ? ['status' => 'blocked']
            : ['status' => $account->password ? 'active' : 'invited', 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        if ($blocked) {
            PatientAccountToken::query()->where('account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);
        }

        return $account;
    }

    // ------------------------------------------------------------------ paciente

    /** @return array{account: ?PatientAccount, error: ?string} */
    public function attempt(Company $company, string $login, string $password, string $ip): array
    {
        $login = mb_strtolower(trim($login));
        $key = 'portal-login:'.$company->id.':'.sha1($login.'|'.$ip);

        if (RateLimiter::tooManyAttempts($key, config('aivexa.security.login_rate_per_minute'))) {
            $this->audit->record('portal.login.throttled', result: 'denied', companyId: $company->id, actorType: 'anonymous');

            return ['account' => null, 'error' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($key).' segundos.'];
        }
        RateLimiter::hit($key, 60);

        $account = $this->findByLogin($login);

        if (! $account) {
            Hash::make($password); // custo equivalente (anti timing/enumeração)
            $this->audit->record('portal.login.failed', result: 'failure', metadata: ['reason' => 'unknown'], companyId: $company->id, actorType: 'anonymous');

            return ['account' => null, 'error' => self::NEUTRAL];
        }
        if ($account->isLocked()) {
            return ['account' => null, 'error' => 'Acesso temporariamente bloqueado por tentativas inválidas. Tente mais tarde.'];
        }
        if (! $account->password || ! Hash::check($password, $account->password)) {
            $this->registerFailure($account);

            return ['account' => null, 'error' => $account->isLocked() ? 'Acesso temporariamente bloqueado por tentativas inválidas. Tente mais tarde.' : self::NEUTRAL];
        }
        $patient = $account->patient;
        if (! $account->isActive() || ! $patient || $patient->status !== 'active' || $patient->isAnonymized()) {
            $this->audit->record('portal.login.failed', $account, result: 'denied', metadata: ['reason' => 'inactive'], actorType: 'anonymous');

            return ['account' => null, 'error' => 'Acesso ao portal indisponível. Fale com a clínica.'];
        }

        RateLimiter::clear($key);
        $account->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip])->save();

        return ['account' => $account, 'error' => null];
    }

    public function tokenRecord(string $token): ?PatientAccountToken
    {
        $record = PatientAccountToken::query()->with('account')->where('token_hash', hash('sha256', $token))->first();

        return $record && $record->isUsable() && $record->account && $record->account->status !== 'blocked' ? $record : null;
    }

    public function setPassword(PatientAccountToken $record, string $password): PatientAccount
    {
        return DB::transaction(function () use ($record, $password) {
            $locked = PatientAccountToken::query()->whereKey($record->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isUsable()) {
                throw new BusinessRuleViolation('Link expirado ou já utilizado. Peça um novo à clínica.', 'portal_token_invalid', 410);
            }

            $account = $locked->account;
            $account->forceFill([
                'password' => $password, 'status' => 'active', 'activated_at' => $account->activated_at ?? now(),
                'failed_login_attempts' => 0, 'locked_until' => null,
            ])->save();
            PatientAccountToken::query()->where('account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);
            $this->audit->record($locked->purpose === 'activation' ? 'portal.activated' : 'portal.password_reset', $account, actorType: 'anonymous');

            return $account;
        });
    }

    /** "Esqueci a senha": sempre resposta neutra; e-mail só para conta ativa com e-mail. */
    public function requestReset(string $cpf, string $birthDate): void
    {
        $cpf = Format::digits($cpf) ?? '';
        $patient = $cpf !== '' ? Patient::query()->where('cpf', $cpf)->whereDate('birth_date', $birthDate)->first() : null;
        $account = $patient ? PatientAccount::query()->where('patient_id', $patient->id)->first() : null;

        if (! $account || ! $account->isActive() || ! $account->email) {
            $this->audit->record('portal.reset_requested', result: 'failure', actorType: 'anonymous');

            return;
        }

        [$token] = $this->newToken($account, 'reset', null);
        $this->sendLink($account, route('portal.activate', ['clinic' => $this->slug($account), 'token' => $token]), 'reset');
        $this->audit->record('portal.reset_requested', $account, actorType: 'anonymous');
    }

    public function changePassword(PatientAccount $account, string $current, string $new): void
    {
        if (! Hash::check($current, $account->password)) {
            throw new BusinessRuleViolation('Senha atual incorreta.', 'invalid_current_password');
        }
        $account->forceFill(['password' => $new])->save();
        $this->audit->record('portal.password_changed', $account);
    }

    // ------------------------------------------------------------------ internos

    private function findByLogin(string $login): ?PatientAccount
    {
        if (str_contains($login, '@')) {
            return PatientAccount::query()->where('email', $login)->first();
        }

        $cpf = Format::digits($login);

        return $cpf && strlen($cpf) === 11
            ? PatientAccount::query()->whereIn('patient_id', Patient::query()->where('cpf', $cpf)->select('id'))->first()
            : null;
    }

    private function registerFailure(PatientAccount $account): void
    {
        $attempts = $account->failed_login_attempts + 1;
        $data = ['failed_login_attempts' => $attempts];

        if ($attempts >= config('aivexa.security.lockout_threshold')) {
            $data = ['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(config('aivexa.security.lockout_minutes'))];
        }

        $account->forceFill($data)->save();
        $this->audit->record(isset($data['locked_until']) ? 'portal.account_locked' : 'portal.login.failed', $account, result: 'failure', metadata: ['attempts' => $attempts], actorType: 'anonymous');
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    private function newToken(PatientAccount $account, string $purpose, ?string $createdBy): array
    {
        PatientAccountToken::query()->where('account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);

        $token = Str::random(48);
        $expires = $purpose === 'activation'
            ? CarbonImmutable::now()->addHours(config('aivexa.portal.activation_ttl_hours'))
            : CarbonImmutable::now()->addMinutes(config('aivexa.portal.reset_ttl_minutes'));

        PatientAccountToken::create(['account_id' => $account->id, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'created_by' => $createdBy]);
        $this->audit->record('portal.link_issued', $account, metadata: ['purpose' => $purpose, 'expires_at' => $expires->toIso8601String()], userId: $createdBy);

        return [$token, $expires];
    }

    private function slug(PatientAccount $account): string
    {
        return (string) Company::query()->whereKey($account->company_id)->value('slug');
    }
}
