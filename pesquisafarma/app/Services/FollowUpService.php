<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PrizeCampaign;
use App\Models\Quote;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Text;

/**
 * Pós-venda automático pelo WhatsApp — UMA mensagem com as 3 perguntas, para o cliente responder rápido:
 *
 *   1️⃣ Seu pedido chegou?            → S ou N
 *   2️⃣ De 0 a 10, indicaria a gente? → número
 *   3️⃣ Foto da nota fiscal           → concorre aos brindes do mês e ganha pontos
 *   Ex.: responda "S 10" e mande a foto da nota.
 *
 * Aceita resposta junta ("S 10", "sim, 9", "chegou nota 10") ou em partes; só pergunta de novo o que faltou.
 * N (não chegou) avisa a farmácia e o televendas e repete a pergunta em 1 hora.
 * Qualquer foto enviada durante o pós-venda é registrada como nota fiscal (sem precisar escrever NOTA).
 *
 * Cotação apresentada sem resposta → "Responda o número da opção, COMPREI ou DESISTI".
 * COMPREI → mesma pesquisa (sem a pergunta da entrega): indicação + nota (a nota mostra onde comprou).
 */
class FollowUpService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly WhatsAppService $wa) {}

    // ------------------------------------------------------------------ Agendamento

    public function scheduleForOrder(Order $order, ?int $etaMinutes = null): void
    {
        $minutes = $order->fulfillment === 'entrega' ? ($etaMinutes ?? 60) + 30 : (int) Setting::get('followup_minutes');
        $order->forceFill(['followup_at' => now()->addMinutes($minutes)])->save();
    }

    public function scheduleForQuote(Quote $quote): void
    {
        $quote->forceFill(['followup_at' => now()->addMinutes((int) Setting::get('followup_minutes'))])->save();
    }

    /** Envia as pesquisas vencidas (rodado pelo agendador a cada 5 minutos, das 8h às 21h). */
    public function runDue(): int
    {
        $n = 0;
        $orders = Order::query()->where('status', 'confirmado')->whereNotNull('followup_at')->where('followup_at', '<=', now())
            ->where('followup_count', '<', self::MAX_ATTEMPTS)->with(['customer', 'pharmacy', 'quote'])->get();
        foreach ($orders as $order) {
            if (! $this->busy($order->customer)) {
                $this->askPostSale($order->customer, $order->quote, $order, askDelivery: true);
                $n++;
            }
        }
        $quotes = Quote::query()->where('status', 'apresentada')->whereNotNull('followup_at')->where('followup_at', '<=', now())
            ->where('followup_count', '<', 2)->with('customer')->get();
        foreach ($quotes as $quote) {
            if (! $this->busy($quote->customer)) {
                $this->askPurchase($quote);
                $n++;
            }
        }

        return $n;
    }

    /** Não interrompe um cadastro ou outra pesquisa em andamento. */
    private function busy(Customer $c): bool
    {
        return in_array($c->bot_state, array_merge(Customer::REGISTRATION_STATES, ['pos_venda']), true);
    }

    // ------------------------------------------------------------------ Mensagens

    /** A mensagem única do pós-venda (entrega + indicação + nota fiscal). */
    public function askPostSale(Customer $customer, ?Quote $quote, ?Order $order, bool $askDelivery): void
    {
        $first = explode(' ', trim((string) $customer->name))[0] ?: 'tudo bem';
        $campaign = PrizeCampaign::query()->where('month', now()->format('Y-m'))->whereNull('drawn_at')->first();
        $hasReceipt = $order && $order->receipts()->exists();
        $n = 1;
        $lines = ["Oi, {$first}! 😊 Pesquisa rapidinha".($order ? " sobre o pedido *{$order->code}* ({$order->pharmacy->trade_name})" : '').':', ''];
        if ($askDelivery) {
            $lines[] = $this->num($n++).' Seu pedido '.($order?->fulfillment === 'retirada' ? 'foi retirado' : 'chegou').'? *S* ou *N*';
        }
        $lines[] = $this->num($n++).' De *0 a 10*, quanto você *indicaria* a Pesquisa Farma a um amigo?';
        if (! $hasReceipt) {
            $lines[] = $this->num($n).' 📸 Mande a *foto da nota fiscal* e ganhe pontos + concorra '.($campaign ? "à premiação *{$campaign->title}*" : 'aos brindes do mês').'! 🎁';
        }
        $lines[] = '';
        $lines[] = 'Responda tudo junto, ex.: *'.($askDelivery ? 'S 10' : '10').'*'.($hasReceipt ? '' : ' e mande a foto da nota').' 😉';

        $this->wa->toCustomer($customer, implode("\n", $lines), $quote?->id);
        if ($order && $askDelivery) {
            $order->forceFill(['followup_at' => null, 'followup_count' => $order->followup_count + 1])->save();
        }
        if (! $hasReceipt) {
            $quote?->forceFill(['receipt_requested_at' => now()])->save();
        }
        $customer->update(['bot_state' => 'pos_venda', 'bot_data' => array_filter([
            'order_id' => $order?->id, 'quote_id' => $quote?->id,
            'need_delivery' => $askDelivery, 'need_nps' => $quote?->nps_score === null, 'need_receipt' => ! $hasReceipt,
        ], fn ($v) => $v !== null && $v !== false)]);
        Activity::log('posvenda.pesquisa', 'Pesquisa de pós-venda enviada'.($order ? " (pedido {$order->code})" : ''), $order ?? $quote);
    }

    public function askPurchase(Quote $quote): void
    {
        $this->wa->toCustomer($quote->customer, "Oi! Sobre a sua cotação *{$quote->code}*: ainda quer fazer o pedido? 💊\n\n".
            "• Responda o *número da opção* (1, 2 ou 3) e a gente já encaminha;\n• Já comprou? Responda *COMPREI*;\n• Desistiu? Responda *DESISTI*.", $quote->id);
        $quote->forceFill(['followup_at' => $quote->followup_count === 0 ? now()->addDay() : null, 'followup_count' => $quote->followup_count + 1])->save();
        $quote->customer->update(['bot_state' => 'escolha', 'bot_data' => ['quote_id' => $quote->id]]);
        Activity::log('posvenda.compra', "Perguntado ao cliente se comprou (cotação {$quote->code})", $quote);
    }

    private function num(int $n): string
    {
        return [1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣'][$n] ?? $n.'.';
    }

    // ------------------------------------------------------------------ Respostas

    /** Trata a resposta do cliente no pós-venda. Retorna true se tratou (fotos seguem para o robô registrar a nota). */
    public function handle(Customer $customer, string $text, bool $hasMedia, QuoteService $quotes): bool
    {
        $data = (array) ($customer->bot_data ?? []);
        $t = Text::slug($text);
        $order = isset($data['order_id']) ? Order::find($data['order_id']) : null;
        $quote = isset($data['quote_id']) ? Quote::find($data['quote_id']) : $order?->quote;

        if ($customer->bot_state === 'escolha' && $quote) {
            if (preg_match('/^(comprei|ja comprei|ja compr)/', $t)) {
                $quote->update(['customer_reported' => 'comprou', 'status' => 'finalizada', 'closed_at' => now(), 'followup_at' => null]);
                Activity::log('posvenda.comprou', "Cliente disse que já comprou (cotação {$quote->code}) — aguardando nota fiscal", $quote);
                $this->askPostSale($customer, $quote, null, askDelivery: false);

                return true;
            }
            if (preg_match('/^(desisti|nao quero|nao vou|nao preciso)/', $t)) {
                $quote->update(['customer_reported' => 'desistiu', 'followup_at' => null]);
                $quotes->markLost($quote, 'Cliente desistiu (pós-venda)');
                $customer->update(['bot_state' => null, 'bot_data' => null]);
                $this->wa->toCustomer($customer, 'Tudo bem! Quando precisar, é só mandar a lista ou a foto da receita. 💊', $quote->id);

                return true;
            }

            return false;
        }

        if ($customer->bot_state === 'aguardando_nota') {
            if ($hasMedia) {
                return false; // o robô registra a foto como nota fiscal
            }
            $customer->update(['bot_state' => null, 'bot_data' => null]);

            return false;
        }

        if ($customer->bot_state !== 'pos_venda') {
            return false;
        }
        if ($hasMedia) {
            // Foto da nota: o robô registra; quem tem a nota recebeu o pedido.
            if (! empty($data['need_delivery']) && $order) {
                $this->confirmDelivery($order);
                unset($data['need_delivery']);
            }
            unset($data['need_receipt']);
            $this->parseAnswers($customer, $text, $data, $order, $quote, $quotes, silent: true);

            return false;
        }

        return $this->parseAnswers($customer, $text, $data, $order, $quote, $quotes);
    }

    /** Lê "S 10", "sim, 9", "N", "10"... e pergunta de novo só o que faltou. */
    private function parseAnswers(Customer $customer, string $text, array $data, ?Order $order, ?Quote $quote, QuoteService $quotes, bool $silent = false): bool
    {
        $t = Text::slug($text);
        $understood = false;
        $notDelivered = false;

        if (! empty($data['need_delivery']) && $order) {
            if (preg_match('/^(s|sim|chegou|recebi|retirei|ja chegou|ja recebi|ok)\b/', $t) || preg_match('/\b(chegou|recebi|retirei)\b/', $t) && ! preg_match('/\bnao\b/', $t)) {
                $this->confirmDelivery($order);
                unset($data['need_delivery']);
                $understood = true;
            } elseif (preg_match('/^(n|nao|ainda nao|ainda)\b/', $t) || preg_match('/\b(nao chegou|nao recebi)\b/', $t)) {
                $detail = trim(preg_replace('/^\s*(n|não|nao|ainda não|ainda nao)\b[\s,.:-]*(\d{1,2}\b)?[\s,.:-]*/iu', '', $text));
                $this->registerIssue($order, 'Cliente informou que não recebeu o pedido'.($detail !== '' && ! ctype_digit($detail) ? ': '.mb_substr($detail, 0, 300) : ''));
                $order->forceFill(['followup_at' => now()->addHour()])->save();
                unset($data['need_delivery']);
                $understood = $notDelivered = true;
            }
        }
        if (! empty($data['need_nps']) && preg_match('/(?<!\d)(10|[0-9])(?!\d)/', $text, $m) && $quote) {
            $quotes->saveNps($quote, (int) $m[1]);
            unset($data['need_nps']);
            $understood = true;
        }

        if (! $understood && ! $silent) {
            // Não entendeu: se for outro assunto, segue o atendimento normal.
            if (mb_strlen($text) > 25) {
                $customer->update(['bot_state' => empty($data['need_receipt']) ? null : 'aguardando_nota', 'bot_data' => $data ?: null]);

                return false;
            }
            $this->wa->toCustomer($customer, 'Não entendi 😅 Responda por exemplo *'.(! empty($data['need_delivery']) ? 'S 10' : '10').'*'.(! empty($data['need_receipt']) ? ' e mande a foto da nota.' : '.'), $quote?->id);

            return true;
        }

        $missing = [];
        if (! empty($data['need_delivery'])) {
            $missing[] = 'o pedido chegou? *S* ou *N*';
        }
        if (! empty($data['need_nps'])) {
            $missing[] = 'de *0 a 10*, quanto nos indicaria?';
        }

        if ($missing) {
            $customer->update(['bot_data' => $data]);
            if (! $silent) {
                $this->wa->toCustomer($customer, 'Obrigado! Só falta: '.implode(' E ', $missing), $quote?->id);
            }

            return true;
        }

        // Respondeu tudo
        $score = $quote?->fresh()->nps_score;
        $msg = $notDelivered ? '😕 Poxa! Já avisamos a farmácia e nossa equipe vai acompanhar sua entrega. Obrigado pela resposta!'
            : ($score !== null && $score >= 9 ? 'Muito obrigado! 💚' : ($score !== null && $score <= 6 ? 'Obrigado pela sinceridade! Se quiser, conte o que podemos melhorar. 🙏' : 'Obrigado! 🙌'));
        if (! empty($data['need_receipt'])) {
            $msg .= "\n📸 Não esqueça: mande a *foto da nota fiscal* para ganhar pontos e concorrer aos brindes!";
            $customer->update(['bot_state' => 'aguardando_nota', 'bot_data' => array_filter(['order_id' => $order?->id, 'quote_id' => $quote?->id])]);
        } else {
            $customer->update(['bot_state' => null, 'bot_data' => null]);
        }
        if (! $silent) {
            $this->wa->toCustomer($customer, $msg, $quote?->id);
        }

        return true;
    }

    private function confirmDelivery(Order $order): void
    {
        $order->forceFill(['delivery_confirmed_at' => now(), 'followup_at' => null, 'delivery_issue_resolved_at' => $order->delivery_issue ? now() : $order->delivery_issue_resolved_at])->save();
        if ($order->status === 'confirmado') {
            $order->update(['status' => 'entregue', 'completed_at' => now()]);
        }
        $order->quote?->update(['status' => 'finalizada', 'closed_at' => $order->quote->closed_at ?? now()]);
        Activity::log('posvenda.entregue', "Cliente confirmou o recebimento do pedido {$order->code}", $order);
    }

    private function registerIssue(Order $order, string $issue): void
    {
        $order->forceFill(['delivery_issue' => mb_substr($issue, 0, 500), 'delivery_issue_at' => now(), 'delivery_issue_resolved_at' => null])->save();
        $customer = $order->customer;
        $this->wa->toPharmacy($order->pharmacy, "⚠️ *Pesquisa Farma — pedido {$order->code}*\n{$issue}.\nCliente: {$customer->displayName()} · ".Text::formatPhone($customer->phone), $order->quote_id);
        Activity::log('posvenda.problema', "Pedido {$order->code}: {$issue}", $order);
    }
}
