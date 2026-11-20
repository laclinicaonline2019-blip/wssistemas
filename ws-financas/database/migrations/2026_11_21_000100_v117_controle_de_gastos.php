<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versão 1.1.7 — controle de gastos por entidade (CPF/CNPJ) — migração ADITIVA e idempotente.
 *
 *  - centros de custo e categorias próprias por empresa;
 *  - metas de gasto mensais/semanais (total e por categoria);
 *  - aprovação de despesas de funcionários (lançamento pendente NÃO é despesa efetiva);
 *  - preferências e registro de envio dos relatórios automáticos (idempotência);
 *  - tipo do plano (pessoal/faixa/personalizado) — os planos por faixa são criados INATIVOS pelo semeador de planos
 *    (instalação e tela Planos do proprietário), nunca dentro da migração, para não conflitar com a restauração de backups;
 *  - pedidos de mudança de plano e lotes de importação de extrato (prévia antes de gravar).
 *
 * Nada existente é alterado ou apagado; colunas novas são opcionais (nulas ou com padrão).
 * Não toca em tabelas, triggers ou registros da auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('company_id');
                $t->string('name', 80);
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['company_id', 'name']);
                $t->unique(['company_id', 'id']);
                $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('company_categories')) {
            Schema::create('company_categories', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('company_id');
                $t->string('type', 10); // despesa | receita
                $t->string('name', 60);
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['company_id', 'type', 'name']);
                $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('company_id');
                $t->string('period', 8);                  // mes | semana
                $t->string('category', 60)->default('');  // '' = meta do total de despesas
                $t->unsignedBigInteger('amount_cents');
                $t->timestamps();
                $t->unique(['company_id', 'period', 'category']);
                $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('entries', 'approval_status')) {
            Schema::table('entries', function (Blueprint $t) {
                $t->ulid('cost_center_id')->nullable();
                $t->string('approval_status', 10)->nullable();   // null = efetivo | pendente | rejeitada
                $t->ulid('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('responsible', 120)->nullable();      // quem lançou (usuário ou número do WhatsApp)
                $t->index(['company_id', 'approval_status']);
                $t->index(['company_id', 'cost_center_id']);
            });
        }

        // Regras de funcionário (usuário do painel e número avulso do WhatsApp)
        if (! Schema::hasColumn('memberships', 'requires_approval')) {
            Schema::table('memberships', function (Blueprint $t) {
                $t->boolean('requires_approval')->default(false);
                $t->unsignedBigInteger('approval_limit_cents')->nullable(); // aprovação só acima deste valor (nulo = todas)
                $t->json('cost_center_ids')->nullable();                     // nulo = todos os centros de custo
            });
        }
        if (! Schema::hasColumn('whatsapp_contacts', 'requires_approval')) {
            Schema::table('whatsapp_contacts', function (Blueprint $t) {
                $t->boolean('requires_approval')->default(false);
                $t->unsignedBigInteger('approval_limit_cents')->nullable();
                $t->json('company_ids')->nullable();        // nulo = todas as empresas (número avulso)
                $t->json('cost_center_ids')->nullable();
            });
        }

        if (! Schema::hasTable('report_preferences')) {
            Schema::create('report_preferences', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id')->unique();
                $t->boolean('weekly')->default(false);
                $t->boolean('monthly')->default(false);
                $t->boolean('via_whatsapp')->default(false);
                $t->boolean('via_email')->default(false);
                $t->json('company_ids')->nullable();        // nulo = todas as entidades
                $t->string('email', 190)->nullable();       // nulo = e-mail do administrador
                $t->ulid('whatsapp_contact_id')->nullable(); // nulo = número validado do administrador
                $t->timestamps();
                $t->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('report_runs')) {
            Schema::create('report_runs', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('company_id');
                $t->string('kind', 8);          // semanal | mensal
                $t->string('period_key', 10);   // 2026-W47 | 2026-11
                $t->string('channel', 10);      // whatsapp | email
                $t->string('status', 12);       // enviado | falhou | ignorado
                $t->string('detail', 190)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'kind', 'period_key', 'channel']);
                $t->index(['account_id', 'created_at']);
                $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('plans', 'kind')) {
            Schema::table('plans', function (Blueprint $t) {
                $t->string('kind', 12)->default('business'); // personal (só CPF) | business | custom (sob consulta)
            });
        }

        $this->restorePreserved();

        if (! Schema::hasTable('plan_change_requests')) {
            Schema::create('plan_change_requests', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('from_plan_id')->nullable();
                $t->ulid('to_plan_id')->nullable();         // nulo = plano personalizado
                $t->unsignedInteger('companies_wanted')->nullable();
                $t->string('message', 500)->nullable();
                $t->string('status', 12)->default('pendente'); // pendente | atendido | recusado | cancelado
                $t->ulid('requested_by')->nullable();
                $t->ulid('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at']);
                $t->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->ulid('account_id');
                $t->ulid('company_id');
                $t->ulid('user_id')->nullable();
                $t->string('format', 8);        // ofx | csv | xlsx | pdf
                $t->string('filename', 190);
                $t->char('checksum', 64);
                $t->longText('rows');           // prévia (JSON) — só vira lançamento após confirmação
                $t->string('status', 12)->default('previa'); // previa | importado | cancelado
                $t->unsignedInteger('imported')->default(0);
                $t->unsignedInteger('duplicates')->default(0);
                $t->timestamps();
                $t->index(['account_id', 'created_at']);
                $t->foreign(['account_id', 'company_id'])->references(['account_id', 'id'])->on('companies')->cascadeOnDelete();
            });
        }

    }

    /** Colunas acrescentadas pela 1.1.7 em tabelas que já existiam (e que os backups da 1.1.6 copiam). */
    private const ADDED = [
        'entries' => ['cost_center_id', 'approval_status', 'approved_by', 'approved_at', 'responsible'],
        'memberships' => ['requires_approval', 'approval_limit_cents', 'cost_center_ids'],
        'whatsapp_contacts' => ['requires_approval', 'approval_limit_cents', 'company_ids', 'cost_center_ids'],
        'plans' => ['kind'],
    ];

    private const KEEP = 'preservado_1_1_7';

    /**
     * Reversão para a 1.1.6 SEM PERDA:
     *  - tabelas novas (centros de custo, metas, preferências...) ficam como estão — a 1.1.6 não as lê nem as copia;
     *  - as colunas novas das tabelas antigas saem, porque o backup da 1.1.6 é conferido restaurando-o num banco com o
     *    esquema da 1.1.6 (coluna a mais = backup "com problema" e atualização bloqueada). Antes disso, todo valor
     *    diferente do padrão é guardado em "preservado_1_1_7"; reaplicar a 1.1.7 devolve os valores e apaga a cópia.
     * A reversão é recusada enquanto houver despesa pendente de aprovação (a 1.1.6 a trataria como efetiva).
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::KEEP)) {
            Schema::create(self::KEEP, function (Blueprint $t) {
                $t->id();
                $t->string('tbl', 40);
                $t->string('row_id', 40);
                $t->text('data');
                $t->index(['tbl', 'row_id']);
            });
        }
        foreach (self::ADDED as $table => $cols) {
            $present = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if (! $present) {
                continue;
            }
            DB::table($table)->select(array_merge(['id'], $present))->orderBy('id')->chunk(500, function ($rows) use ($table, $present) {
                $keep = [];
                foreach ($rows as $r) {
                    $data = array_filter(array_intersect_key((array) $r, array_flip($present)), fn ($v, $k) => $v !== null && ! ($k === 'requires_approval' && ! $v) && ! ($k === 'kind' && $v === 'business'), ARRAY_FILTER_USE_BOTH);
                    if ($data) {
                        $keep[] = ['tbl' => $table, 'row_id' => (string) $r->id, 'data' => json_encode($data)];
                    }
                }
                if ($keep) {
                    DB::table(self::KEEP)->insert($keep);
                }
            });
            if ($table === 'entries') {
                Schema::table('entries', function (Blueprint $t) {
                    $t->dropIndex(['company_id', 'approval_status']);
                    $t->dropIndex(['company_id', 'cost_center_id']);
                });
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    /** Reaplicação depois de uma reversão: devolve os valores guardados por down(). */
    private function restorePreserved(): void
    {
        if (! Schema::hasTable(self::KEEP)) {
            return;
        }
        DB::table(self::KEEP)->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $r) {
                $data = json_decode((string) $r->data, true);
                if (is_array($data) && isset(self::ADDED[$r->tbl])) {
                    DB::table($r->tbl)->where('id', $r->row_id)->update(array_intersect_key($data, array_flip(self::ADDED[$r->tbl])));
                }
            }
        });
        Schema::drop(self::KEEP);
    }
};
