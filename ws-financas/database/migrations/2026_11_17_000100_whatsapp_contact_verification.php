<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validação do número de WhatsApp (versão 1.1.2) — migração ADITIVA.
 *
 * Um número cadastrado no painel só passa a lançar/consultar depois que o próprio celular envia o código de validação
 * mostrado ao administrador (prova de posse do número; evita que um erro de digitação entregue dados financeiros a um
 * estranho). Números que já estavam autorizados antes desta versão são considerados validados (data do cadastro).
 * Não toca em tabelas de auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_contacts', 'verified_at')) {
            Schema::table('whatsapp_contacts', function (Blueprint $t) {
                $t->timestamp('verified_at')->nullable();
                $t->char('verify_code_hash', 64)->nullable();
                $t->timestamp('verify_expires_at')->nullable();
                $t->unsignedTinyInteger('verify_attempts')->default(0);
            });
            DB::table('whatsapp_contacts')->whereNull('verified_at')->update(['verified_at' => DB::raw('created_at')]);
        }
    }

    /** Nada é removido automaticamente (as colunas são opcionais para versões anteriores). */
    public function down(): void {}
};
