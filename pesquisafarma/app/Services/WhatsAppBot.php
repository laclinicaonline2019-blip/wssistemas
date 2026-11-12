<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Neighborhood;
use App\Models\Quote;
use App\Models\QuotePharmacy;
use App\Models\Setting;
use App\Models\WaMessage;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Text;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atendimento automático no WhatsApp:
 *  1) primeiro contato → cadastro (nome, CEP/bairro, endereço, LGPD, promoções);
 *  2) texto ou foto → cotação (a IA monta os itens; o televendas confere e envia às farmácias);
 *  3) foto com "NOTA" na legenda → captação da venda pela nota fiscal;
 *  4) resposta 1/2/3 após a cotação → pedido na farmácia escolhida;
 *  5) nota de 1 a 5 → avaliação do atendimento.
 * Tudo fica registrado na conversa, visível para todos os televendas.
 */
class WhatsAppBot
{
    public function __construct(
        private readonly WhatsAppService $wa,
        private readonly QuoteService $quotes,
        private readonly ReceiptService $receipts,
        private readonly CepLookup $cep,
    ) {}

    public function handle(InboundMessage $in): void
    {
        $phone = Text::phone($in->phone);
        if ($in->providerId && WaMessage::query()->where('provider_id', $in->providerId)->where('direction', 'in')->exists()) {
            return; // webhook repetido
        }
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['origin' => 'whatsapp', 'bot_state' => 'novo']);
        $customer->update(['last_contact_at' => now()]);
        $mediaPath = null;
        if ($in->hasMedia()) {
            $mediaPath = 'whatsapp/'.now()->format('Y/m').'/'.Str::random(24).'.'.$this->ext($in->mime);
            Storage::disk('local')->put($mediaPath, $in->mediaBytes);
        }
        $msg = WaMessage::create([
            'customer_id' => $customer->id, 'phone' => $phone, 'direction' => 'in', 'type' => $in->type,
            'body' => $in->text, 'media_path' => $mediaPath, 'mime' => $in->mime, 'provider_id' => $in->providerId,
        ]);

        $text = trim((string) $in->text);
        if (in_array(Text::slug($text), ['sair', 'parar', 'cancelar promocoes'], true)) {
            $customer->update(['accepts_marketing' => false]);
            $this->reply($customer, 'Pronto! Você não vai mais receber promoções. Para cotações, é só mandar a lista ou a foto da receita. 😉');

            return;
        }

        if (! $customer->isRegistered()) {
            $this->registration($customer, $in, $msg, $text);

            return;
        }

        if ($customer->bot_state === 'avaliacao' && preg_match('/^\s*([1-5])\b/', $text, $m)) {
            $quote = Quote::find($customer->bot_data['quote_id'] ?? 0);
            if ($quote) {
                $this->quotes->saveFeedback($quote, (int) $m[1], trim(substr($text, strlen($m[0]))) ?: null);
            }
            $customer->update(['bot_state' => null, 'bot_data' => null]);
            $this->reply($customer, (int) $m[1] >= 4 ? 'Muito obrigado pela avaliação! 💚' : 'Obrigado pela avaliação. Vamos melhorar! Se quiser, conte o que aconteceu. 🙏');

            return;
        }

        if ($customer->bot_state === 'escolha' && preg_match('/^\s*(?:op[cç][aã]o\s*)?([1-9])\s*$/iu', $text, $m)) {
            $this->chooseOption($customer, (int) $m[1]);

            return;
        }

        if ($in->hasMedia() && preg_match('/\b(nota|cupom|comprovante|nf)\b/iu', $text)) {
            $receipt = $this->receipts->create($customer, $in->mediaBytes, (string) ($in->mime ?: 'image/jpeg'), 'whatsapp');
            $this->reply($customer, "📄 Recebemos sua nota fiscal ({$receipt->code})! Vamos conferir e lançar seus pontos. Obrigado! 🎁");

            return;
        }

        if (! $in->hasMedia() && $this->isSmallTalk($text)) {
            $this->reply($customer, "Olá, {$this->firstName($customer)}! 👋\nPara cotar, envie a *lista dos medicamentos* ou a *foto da receita*.\n📄 Para ganhar pontos, envie a foto da *nota fiscal* com a palavra NOTA.");

            return;
        }

        $this->toQuote($customer, $in, $msg, $text);
    }

    private function toQuote(Customer $customer, InboundMessage $in, WaMessage $msg, string $text): void
    {
        $draft = $this->quotes->openDraft($customer);
        if ($draft) {
            $this->quotes->appendMessage($draft, $text ?: null, $in->mediaBytes, $in->mime);
            $msg->update(['quote_id' => $draft->id]);

            return;
        }
        $quote = $this->quotes->createFromMessage($customer, $text ?: null, $in->mediaBytes, $in->mime);
        $msg->update(['quote_id' => $quote->id]);
        $this->reply($customer, "✅ Recebemos seu pedido de cotação *{$quote->code}*!\nJá estamos consultando as farmácias perto de você".
            ($customer->neighborhood ? " ({$customer->neighborhood->name})" : '').'. Em poucos minutos enviamos a melhor cotação. ⏱️', $quote->id);

        if (Setting::get('auto_dispatch') === '1' && $quote->items()->exists()) {
            $ids = $this->quotes->candidates($quote)->take((int) Setting::get('max_pharmacies'))->pluck('pharmacy.id')->all();
            if ($ids) {
                $this->quotes->dispatch($quote, $ids);
            }
        }
    }

    private function chooseOption(Customer $customer, int $option): void
    {
        $quote = Quote::find($customer->bot_data['quote_id'] ?? 0);
        $dispatch = $quote ? QuotePharmacy::query()->where('quote_id', $quote->id)->where('status', 'respondida')->where('rank', $option)->first() : null;
        if (! $quote || ! $dispatch) {
            $this->reply($customer, 'Não encontrei essa opção. Um atendente vai te ajudar em instantes. 🙏');

            return;
        }
        try {
            $this->quotes->createOrder($quote, $dispatch);
        } catch (Throwable $e) {
            $this->reply($customer, 'Não consegui registrar o pedido automaticamente ('.$e->getMessage().'). Um atendente vai te ajudar. 🙏', $quote->id);
            Activity::log('pedido.falha_bot', 'Falha ao gerar pedido pelo WhatsApp: '.$e->getMessage(), $quote);
        }
    }

    // ------------------------------------------------------------------ Cadastro

    private function registration(Customer $customer, InboundMessage $in, WaMessage $msg, string $text): void
    {
        $data = (array) ($customer->bot_data ?? []);
        $state = $customer->bot_state ?? 'novo';

        if ($state === 'novo') {
            // Guarda a primeira mensagem (lista/foto) para virar cotação ao fim do cadastro.
            if ($in->hasMedia() || (! $this->isSmallTalk($text) && mb_strlen($text) > 3)) {
                $data['pending'][] = $msg->id;
            }
            $customer->update(['bot_state' => 'nome', 'bot_data' => $data, 'name' => $customer->name ?: null]);
            $this->reply($customer, Setting::get('welcome_message')."\n\nComo é seu primeiro contato, vamos fazer um cadastro rapidinho (1 minuto). 📝\nQual é o seu *nome completo*?");

            return;
        }

        if ($in->hasMedia() || (in_array($state, ['lgpd', 'promo'], true) && mb_strlen($text) > 15 && ! $this->yes($text))) {
            $data['pending'][] = $msg->id; // mandou a receita no meio do cadastro
            $customer->update(['bot_data' => $data]);
        }

        switch ($state) {
            case 'nome':
                if (mb_strlen($text) < 3 || $in->hasMedia()) {
                    $this->reply($customer, 'Por favor, digite seu *nome completo*. 🙂');

                    return;
                }
                $customer->update(['name' => Str::title(mb_substr($text, 0, 120)), 'bot_state' => 'cep', 'bot_data' => $data]);
                $this->reply($customer, "Prazer, {$this->firstName($customer)}! 😊\nQual é o seu *CEP*? (Se não souber, digite o nome do seu *bairro*.)");

                return;

            case 'cep':
                $found = strlen(Text::digits($text)) === 8 ? $this->cep->find($text) : null;
                if ($found) {
                    $n = Neighborhood::findByName($found['neighborhood'], $found['city'])
                        ?? Neighborhood::create(['name' => $found['neighborhood'], 'city' => $found['city'], 'uf' => $found['uf']]);
                    $customer->update(['cep' => $found['cep'], 'address' => $found['address'], 'city' => $found['city'], 'neighborhood_id' => $n->id, 'bot_state' => 'numero']);
                    $this->reply($customer, "📍 {$found['address']} — {$n->name}, {$found['city']}.\nQual o *número* e o *complemento*? (ex.: 120, apto 32)");

                    return;
                }
                if (strlen(Text::digits($text)) === 8) {
                    $this->reply($customer, 'Não encontrei esse CEP. 😕 Digite o nome do seu *bairro*, por favor.');

                    return;
                }
                $n = Neighborhood::findByName($text);
                if (! $n) {
                    if (mb_strlen($text) < 3) {
                        $this->reply($customer, 'Digite o nome do seu *bairro*, por favor.');

                        return;
                    }
                    $n = Neighborhood::create(['name' => Str::title(mb_substr($text, 0, 80)), 'city' => '']);
                    Activity::log('bairro.novo', "Bairro \"{$n->name}\" criado pelo cadastro do WhatsApp (sem coordenadas)", $n);
                }
                $customer->update(['neighborhood_id' => $n->id, 'city' => $customer->city ?: $n->city, 'bot_state' => 'endereco']);
                $this->reply($customer, "Bairro *{$n->name}* ✅\nAgora o *endereço para entrega* (rua, número e complemento):");

                return;

            case 'numero':
                [$number, $complement] = array_pad(array_map('trim', explode(',', $text, 2)), 2, null);
                $customer->update(['number' => mb_substr((string) $number, 0, 20), 'complement' => $complement ? mb_substr($complement, 0, 120) : null, 'bot_state' => 'lgpd']);
                $this->askConsent($customer);

                return;

            case 'endereco':
                $customer->update(['address' => mb_substr($text, 0, 200), 'bot_state' => 'lgpd']);
                $this->askConsent($customer);

                return;

            case 'lgpd':
                if (! $this->yes($text)) {
                    $this->reply($customer, 'Para fazer cotações precisamos guardar seus dados de contato e endereço. Responda *SIM* para concordar. 🙏');

                    return;
                }
                $customer->update(['lgpd_consent_at' => now(), 'bot_state' => 'promo']);
                $this->reply($customer, 'Obrigado! 🙌 Última pergunta: quer receber as *promoções do dia* das farmácias parceiras? Responda *SIM* ou *NÃO*.');

                return;

            case 'promo':
                $pending = (array) ($data['pending'] ?? []);
                $customer->update(['accepts_marketing' => $this->yes($text), 'bot_state' => null, 'bot_data' => null]);
                Activity::log('cliente.cadastro', "Cliente {$customer->name} cadastrado pelo WhatsApp", $customer);
                $this->reply($customer, "🎉 Cadastro concluído, {$this->firstName($customer)}!".($pending ? '' : "\nAgora é só enviar a *lista dos medicamentos* ou a *foto da receita* que fazemos a cotação."));
                $this->createPendingQuote($customer->fresh(), $pending);

                return;
        }
    }

    private function createPendingQuote(Customer $customer, array $messageIds): void
    {
        $messages = WaMessage::query()->whereIn('id', $messageIds)->orderBy('id')->get();
        foreach ($messages as $m) {
            $bytes = $m->media_path ? Storage::disk('local')->get($m->media_path) : null;
            $this->toQuote($customer, new InboundMessage($customer->phone, $m->type, $m->body, null, null, $bytes, $m->mime), $m, (string) $m->body);
        }
    }

    private function askConsent(Customer $customer): void
    {
        $this->reply($customer, "🔒 Seus dados serão usados só para cotar, entregar seus pedidos e registrar seus pontos, conforme a LGPD.\nVocê concorda? Responda *SIM*.");
    }

    // ------------------------------------------------------------------ util

    private function reply(Customer $customer, string $text, ?int $quoteId = null): void
    {
        $this->wa->toCustomer($customer, $text, $quoteId);
    }

    private function yes(string $text): bool
    {
        return (bool) preg_match('/^\s*(sim|s|ok|aceito|concordo|quero|pode|claro|yes)\b/iu', $text);
    }

    private function isSmallTalk(string $text): bool
    {
        $t = Text::slug($text);

        return $t === '' || (bool) preg_match('/^(oi+|ola|bom dia|boa tarde|boa noite|tudo bem|e ai|opa|ok|obrigad[oa]|valeu|menu|ajuda)( .{0,15})?$/', $t);
    }

    private function firstName(Customer $c): string
    {
        return explode(' ', trim((string) $c->name))[0] ?: 'tudo bem';
    }

    private function ext(?string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', 'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg', default => 'jpg'
        };
    }
}
