<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 17 — resultado da varredura dos arquivos enviados (clean = antivírus; basic = verificações próprias). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_files', fn (Blueprint $t) => $t->string('scan_status', 10)->nullable()->after('sha256')->comment('clean, basic'));
        Schema::table('ai_media', fn (Blueprint $t) => $t->string('scan_status', 10)->nullable()->after('sha256')->comment('clean, basic'));
    }

    public function down(): void
    {
        Schema::table('patient_files', fn (Blueprint $t) => $t->dropColumn('scan_status'));
        Schema::table('ai_media', fn (Blueprint $t) => $t->dropColumn('scan_status'));
    }
};
