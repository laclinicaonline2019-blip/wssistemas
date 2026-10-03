<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13 — IA com áudio, imagem e OCR.
 *
 * ai_media: arquivo recebido pelo WhatsApp (áudio, imagem, PDF) ou enviado pela equipe para leitura,
 * guardado em disco privado, com a transcrição (áudio) ou os dados extraídos (OCR) — sempre
 * "não verificado" até a conferência por alguém da equipe. Nunca é excluído fisicamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('company_id', 26);
            $table->string('source', 20)->comment('whatsapp, upload');
            $table->char('message_id', 26)->nullable();
            $table->char('thread_id', 26)->nullable();
            $table->char('patient_id', 26)->nullable();
            $table->char('patient_file_id', 26)->nullable()->comment('Arquivo do paciente lido (upload) ou anexado após a conferência');
            $table->string('kind', 20)->comment('audio, image, document');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 30)->default('local');
            $table->string('path', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('original_name', 191)->nullable();
            $table->string('caption', 1000)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending, processed, failed, skipped');
            $table->text('transcript')->nullable();
            $table->string('doc_type', 30)->nullable();
            $table->json('extraction')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('error', 500)->nullable();
            $table->string('review_status', 20)->default('pending')->comment('pending, verified, discarded');
            $table->char('reviewed_by', 26)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_notes', 1000)->nullable();
            $table->datetimes();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'review_status', 'created_at']);
            $table->index(['company_id', 'message_id']);
            $table->foreign(['company_id', 'patient_id'])->references(['company_id', 'id'])->on('patients')->restrictOnDelete();
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->text('transcription_api_key')->nullable()->after('api_key')->comment('Criptografada. Chave da OpenAI para transcrever áudio (vazio = da plataforma)');
        });

        DB::statement("ALTER TABLE ai_media ADD CONSTRAINT ai_media_check CHECK (source IN ('whatsapp','upload') AND kind IN ('audio','image','document')
            AND status IN ('pending','processed','failed','skipped') AND review_status IN ('pending','verified','discarded'))");
    }

    public function down(): void
    {
        Schema::table('ai_configs', fn (Blueprint $table) => $table->dropColumn('transcription_api_key'));
        Schema::dropIfExists('ai_media');
    }
};
