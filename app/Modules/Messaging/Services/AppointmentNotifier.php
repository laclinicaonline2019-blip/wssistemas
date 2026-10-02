<?php

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mensagens automáticas da agenda: agendamento, lembretes (24 h, 2 h ou horários
 * personalizados), cancelamento, remarcação e falta. Cada envio tem chave única — o
 * paciente nunca recebe o mesmo aviso duas vezes. Falha no envio NUNCA impede a ação
 * da agenda (só fica registrada).
 */
class AppointmentNotifier
{
    public const DEFAULT_REMINDER_HOURS = [24, 2];

    public function __construct(private readonly MessageService $messages, private readonly TenantContext $context) {}

    public function booked(Appointment $a): void
    {
        $this->send('booking_confirmation', $a, "booking:{$a->id}", 'messaging.on_booking');
    }

    public function cancelled(Appointment $a): void
    {
        $this->send('cancellation', $a, "cancel:{$a->id}", 'messaging.on_cancel');
    }

    public function rescheduled(Appointment $a): void
    {
        $this->send('reschedule', $a, "reschedule:{$a->id}:".$a->starts_at->format('YmdHi'), 'messaging.on_reschedule');
    }

    public function noShow(Appointment $a): void
    {
        $this->send('no_show', $a, "noshow:{$a->id}", 'messaging.on_no_show');
    }

    /** @return list<int> horas antes da consulta */
    public function reminderHours(Company $company): array
    {
        $hours = $company->setting('messaging.reminder_hours', self::DEFAULT_REMINDER_HOURS);

        return collect(is_array($hours) ? $hours : explode(',', (string) $hours))->map(fn ($h) => (int) $h)
            ->filter(fn ($h) => $h >= 1 && $h <= 168)->unique()->sortDesc()->values()->all();
    }

    /**
     * Cron (a cada 10 min): lembrete de cada antecedência configurada. Só para agendamentos que já
     * existiam no momento do lembrete (quem marcou 1 h antes não recebe o "lembrete de 24 h").
     */
    public function sendReminders(): int
    {
        $sent = 0;
        $companies = $this->context->runAsSystem(fn () => Company::query()->get()->filter->isOperational());

        foreach ($companies as $company) {
            if (! $company->setting('messaging.reminders_enabled', true)) {
                continue;
            }
            $sent += $this->context->runFor($company->id, function () use ($company) {
                $count = 0;
                $now = CarbonImmutable::now();
                foreach ($this->reminderHours($company) as $h) {
                    Appointment::query()->with(['patient', 'doctor:id,name,social_name', 'branch:id,name,timezone'])
                        ->whereIn('status', ['scheduled', 'confirmed'])
                        ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->addHours($h))
                        ->whereRaw('created_at <= starts_at')->get()
                        ->filter(fn (Appointment $a) => $a->created_at->lte($a->starts_at->subHours($h)))
                        ->each(function (Appointment $a) use ($h, &$count) {
                            $count += $this->send('reminder', $a, "reminder:{$h}:{$a->id}:".$a->starts_at->format('YmdHi')) ? 1 : 0;
                        });
                }

                return $count;
            });
        }

        return $sent;
    }

    public function params(Appointment $a): array
    {
        $a->loadMissing(['patient', 'doctor:id,name,social_name', 'branch:id,name,timezone']);
        $local = $a->starts_at->timezone($a->branch->timezone ?: 'America/Sao_Paulo');

        return [
            'nome' => Str::before(trim($a->patient->social_name ?: $a->patient->name), ' '),
            'data' => $local->format('d/m/Y'), 'hora' => $local->format('H:i'),
            'medico' => $a->doctor->displayName(), 'unidade' => $a->branch->name, 'protocolo' => $a->protocol,
        ];
    }

    private function send(string $purpose, Appointment $a, string $key, ?string $setting = null): bool
    {
        try {
            $company = Company::query()->find($a->company_id);
            if ($setting && ! $company?->setting($setting, true)) {
                return false;
            }
            $a->loadMissing('patient');

            return $this->messages->queueForPatient($purpose, $a->patient, $this->params($a), $a, $key) !== null;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
