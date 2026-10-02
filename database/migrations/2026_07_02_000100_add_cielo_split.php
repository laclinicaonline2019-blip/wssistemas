<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Split da Cielo (Fase 8.1):
 * - API E-commerce Cielo com split (SplittedCreditCard + SplitPayments) e cartão
 *   tokenizado no navegador (Silent Order Post) — provider "cielo_api";
 * - maquininha Cielo (Smart/Flash) com split habilitado pela Cielo — registro da venda
 *   já dividida, sem repasse interno.
 * O médico participa como "subordinado" da clínica (marketplace) na Cielo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('cielo_subordinate_id', 36)->nullable()->comment('SubordinateMerchantId do médico no Split Cielo');
        });

        Schema::table('payment_splits', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->comment('asaas, cielo_api, cielo_terminal (split nativo) ou null (interno)');
        });

        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_check');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_check CHECK (provider IN ('asaas','cielo','cielo_api','mock') AND mode IN ('mock','sandbox','production'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_gateways DROP CONSTRAINT payment_gateways_check');
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_check CHECK (provider IN ('asaas','cielo','mock') AND mode IN ('mock','sandbox','production'))");
        Schema::table('payment_splits', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('cielo_subordinate_id'));
    }
};
