<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp NÃO OFICIAL (Z-API e Evolution API) como alternativa à Cloud API da Meta, escolhida pela
 * clínica. O aceite do risco (bloqueio do número, termos do WhatsApp, LGPD) fica registrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_channels', function (Blueprint $table) {
            $table->dateTime('risk_accepted_at')->nullable()->after('is_active')->comment('Aceite do risco do provedor não oficial');
            $table->char('risk_accepted_by', 26)->nullable()->after('risk_accepted_at');
        });
        DB::statement('ALTER TABLE messaging_channels DROP CONSTRAINT messaging_channels_check');
        DB::statement("ALTER TABLE messaging_channels ADD CONSTRAINT messaging_channels_check CHECK (provider IN ('meta','mock','zapi','evolution') AND mode IN ('mock','test','production'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messaging_channels DROP CONSTRAINT messaging_channels_check');
        DB::table('messaging_channels')->whereIn('provider', ['zapi', 'evolution'])->update(['provider' => 'mock', 'mode' => 'mock', 'is_active' => false]);
        DB::statement("ALTER TABLE messaging_channels ADD CONSTRAINT messaging_channels_check CHECK (provider IN ('meta','mock') AND mode IN ('mock','test','production'))");
        Schema::table('messaging_channels', function (Blueprint $table) {
            $table->dropColumn(['risk_accepted_at', 'risk_accepted_by']);
        });
    }
};
