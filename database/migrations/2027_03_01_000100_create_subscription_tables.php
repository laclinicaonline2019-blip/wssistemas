<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 16 — SaaS comercial: assinatura de cada clínica, faturas da plataforma e eventos de webhook.
 * Períodos com data de fim EXCLUSIVA ([início, fim)). Valores em centavos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->foreignUlid('saas_plan_id')->nullable()->constrained('saas_plans')->restrictOnDelete();
            $table->string('cycle', 10)->default('monthly')->comment('monthly, yearly');
            $table->string('status', 20)->default('trialing')->comment('trialing, active, past_due, suspended, cancelled');
            $table->date('trial_ends_on')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable()->comment('Exclusivo');
            $table->foreignUlid('pending_plan_id')->nullable()->constrained('saas_plans')->restrictOnDelete()->comment('Downgrade/troca agendada para a renovação');
            $table->string('pending_cycle', 10)->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->string('gateway_customer_id', 100)->nullable();
            $table->datetimes();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUlid('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->foreignUlid('saas_plan_id')->nullable()->constrained('saas_plans')->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->string('kind', 20)->comment('renewal, upgrade, manual');
            $table->string('cycle', 10)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_cents');
            $table->date('due_date');
            $table->string('status', 20)->default('open')->comment('open, paid, void');
            $table->string('provider', 20)->nullable();
            $table->string('provider_charge_id', 100)->nullable()->unique();
            $table->string('payment_url', 500)->nullable();
            $table->string('gateway_error', 500)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('paid_cents')->nullable();
            $table->string('paid_via', 20)->nullable()->comment('gateway, manual');
            $table->string('notes', 500)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->datetimes();
            // Uma fatura de renovação por período (cron idempotente).
            $table->string('renewal_key', 60)->nullable()->unique();

            $table->index(['status', 'due_date']);
        });

        Schema::create('platform_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 20);
            $table->string('event_id', 120);
            $table->string('event', 60)->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->string('result', 255)->nullable();
            $table->dateTime('created_at');

            $table->unique(['provider', 'event_id']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_check CHECK (cycle IN ('monthly','yearly') AND status IN ('trialing','active','past_due','suspended','cancelled'))");
        DB::statement("ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_check CHECK (kind IN ('renewal','upgrade','manual') AND status IN ('open','paid','void') AND amount_cents > 0)");

        // Clínicas já existentes: assinatura a partir do estado atual.
        $today = now()->toDateString();
        foreach (DB::table('companies')->whereNull('deleted_at')->get(['id', 'saas_plan_id', 'status', 'trial_ends_at']) as $c) {
            $trial = $c->status === 'trial';
            DB::table('subscriptions')->insert([
                'id' => (string) Str::ulid(), 'company_id' => $c->id, 'saas_plan_id' => $c->saas_plan_id, 'cycle' => 'monthly',
                'status' => match ($c->status) {
                    'trial' => 'trialing', 'suspended' => 'suspended', 'cancelled' => 'cancelled', default => 'active'
                },
                'trial_ends_on' => $trial && $c->trial_ends_at ? substr((string) $c->trial_ends_at, 0, 10) : null,
                'current_period_start' => $trial ? null : $today, 'current_period_end' => $trial ? null : now()->addMonthNoOverflow()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_webhook_events');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
    }
};
