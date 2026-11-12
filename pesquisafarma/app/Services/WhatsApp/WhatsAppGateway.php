<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;

interface WhatsAppGateway
{
    /** Envia texto; devolve o id da mensagem no provedor. Lança exceção em falha. */
    public function sendText(string $phone, string $text): ?string;

    /** Envia imagem (bytes) com legenda. */
    public function sendImage(string $phone, string $bytes, string $mime, ?string $caption = null): ?string;

    /** Converte o webhook do provedor em mensagens recebidas (ignora as enviadas por nós e grupos). */
    public function parse(Request $request): array;
}
