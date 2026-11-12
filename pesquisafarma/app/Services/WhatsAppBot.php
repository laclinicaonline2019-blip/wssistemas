<?php

namespace App\Services;

use App\Models\City;
use App\Models\Customer;
use App\Models\Neighborhood;
use App\Models\Pharmacy;
use App\Models\Quote;
use App\Models\QuotePharmacy;
use App\Models\Setting;
use App\Models\WaMessage;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Geo;
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
 *  5) pós-venda: "seu pedido chegou?", indicação de 0 a 10 e pedido da nota fiscal (concorre a brindes).
 * Tudo fica registrado na conversa, visível para todos os televendas.
 */
class WhatsAppBot
{
    public function __construct(
        private readonly WhatsAppService $wa,
        private readonly QuoteService $quotes,
        private readonly ReceiptService $receipts,
        private readonly CepLookup $cep,
        private readonly FollowUpService $followUp,
        private readonly AiAssistant $ai,
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

        // Pós-venda (uma mensagem com 3 perguntas): chegou? indicaria (0–10)? foto da nota. Também COMPREI/DESISTI.
        $inPostSale = in_array($customer->bot_state, Customer::FOLLOWUP_STATES, true);
        if ($this->followUp->handle($customer, $text, $in->hasMedia(), $this->quotes)) {
            return;
        }
        $customer->refresh();

        if ($customer->bot_state === 'escolha' && preg_match('/^\s*(?:op[cç][aã]o\s*)?([1-9])\s*$/iu', $text, $m)) {
            $this->chooseOption($customer, (int) $m[1]);

            return;
        }

        // Nota fiscal: com a palavra NOTA, ou qualquer foto quando pedimos a nota no pós-venda.
        if ($in->hasMedia() && ($inPostSale || preg_match('/\b(nota|cupom|comprovante|nf)\b/iu', $text))) {
            $this->receiveReceipt($customer, $in->mediaBytes, (string) ($in->mime ?: 'image/jpeg'));

            return;
        }

        // Respondeu se a foto anterior era para cotar ou a nota de uma compra.
        if ($customer->bot_state === 'foto_tipo') {
            $prev = WaMessage::find($customer->bot_data['message_id'] ?? 0);
            $t = Text::slug($text);
            $customer->update(['bot_state' => null, 'bot_data' => null]);
            if ($prev && $prev->media_path && preg_match('/^(2|nota|nota fiscal|compra|cupom|comprei)\b/', $t)) {
                $this->receiveReceipt($customer, Storage::disk('local')->get($prev->media_path), (string) ($prev->mime ?: 'image/jpeg'));

                return;
            }
            if ($prev && $prev->media_path && preg_match('/^(1|cotar|cotacao|receita|orcamento|pesquisar)\b/', $t)) {
                $bytes = Storage::disk('local')->get($prev->media_path);
                $this->toQuote($customer, new InboundMessage($customer->phone, $prev->type, $prev->body, null, null, $bytes, $prev->mime), $prev, (string) $prev->body);

                return;
            }
        }

        // Farmácias credenciadas onde o cartão vale (mais próximas do cliente).
        if (! $in->hasMedia() && preg_match('/^(farmacias?|credenciadas?|farmacias? credenciadas?|onde (posso )?usar( o cartao)?)\??$/', Text::slug($text))) {
            $this->reply($customer, $this->pharmaciesText($customer));

            return;
        }

        // Cliente pergunta os pontos: o robô responde na hora com o cartão fidelidade.
        if (! $in->hasMedia() && preg_match('/^(meus? )?(pontos?|saldo|cartao|cartao fidelidade|fidelidade|quantos pontos( eu)?( tenho)?)\??$/', Text::slug($text))) {
            $loyalty = app(LoyaltyService::class);
            $this->reply($customer, $loyalty->enabled($customer) ? $loyalty->cardText($customer) : 'No momento o programa de pontos não está ativo. 🙏');

            return;
        }

        if (! $in->hasMedia() && $this->isSmallTalk($text)) {
            $this->reply($customer, "Olá, {$this->firstName($customer)}! 👋\nPara cotar, envie a *lista dos medicamentos* ou a *foto da receita*.\n🎁 Comprando pela cotação você ganha ".LoyaltyService::quoteIncentive()."!\n💳 Seus pontos: escreva *PONTOS* · 🏪 Farmácias parceiras: *FARMÁCIAS*.");

            return;
        }

        // Foto sem legenda e sem cotação em aberto: nota fiscal de compra no balcão ou receita para cotar?
        if ($in->hasMedia() && ! $this->quotes->openDraft($customer)) {
            $kind = $this->ai->classifyImage($in->mediaBytes, (string) ($in->mime ?: 'image/jpeg'));
            if ($kind === 'nota_fiscal') {
                $this->receiveReceipt($customer, $in->mediaBytes, (string) ($in->mime ?: 'image/jpeg'));

                return;
            }
            if ($kind === null) {
                $customer->update(['bot_state' => 'foto_tipo', 'bot_data' => ['message_id' => $msg->id]]);
                $this->reply($customer, "📷 Recebemos sua foto! Ela é:\n1️⃣ uma *receita/lista* para cotarmos\n2️⃣ a *nota fiscal* de uma compra (para ganhar pontos)\n\nResponda *1* ou *2*.");

                return;
            }
        }

        $this->toQuote($customer, $in, $msg, $text);
    }

    /** Registra a nota fiscal (compra pela cotação ou direto no balcão com o cartão fidelidade). */
    private function receiveReceipt(Customer $customer, string $bytes, string $mime): void
    {
        $receipt = $this->receipts->create($customer, $bytes, $mime, 'whatsapp');
        $customer->refresh();
        $missing = $customer->bot_state === 'pos_venda' ? (array) $customer->bot_data : [];
        if (empty($missing['need_delivery']) && empty($missing['need_nps'])) {
            $customer->update(['bot_state' => null, 'bot_data' => null]);
        }
        $this->reply($customer, "📄 Recebemos sua nota fiscal ({$receipt->code})! Assim que conferirmos, seus pontos entram e você já está concorrendo aos brindes do mês. 🎁"
            .(! empty($missing['need_nps']) ? "\nAh, e de *0 a 10*, quanto você indicaria a Pesquisa Farma a um amigo?" : ''));
    }

    /** Lista as farmácias credenciadas mais próximas do cliente. */
    private function pharmaciesText(Customer $customer): string
    {
        $origin = $customer->coordinates();
        $list = Pharmacy::query()->where('active', true)->with('neighborhood')->get()
            ->map(fn ($p) => ['p' => $p, 'd' => Geo::distanceKm($origin, $p->lat ? [$p->lat, $p->lng] : null)])
            ->sortBy(fn ($x) => [$x['p']->neighborhood_id === $customer->neighborhood_id ? 0 : 1, $x['d'] ?? 9999])->take(6);
        $lines = ['🏪 *Farmácias credenciadas perto de você*', '💊 *Peça sua cotação aqui antes de comprar*: comparamos o preço nelas, você paga menos e ganha '.LoyaltyService::quoteIncentive().'!', ''];
        foreach ($list as $x) {
            $lines[] = '• *'.$x['p']->trade_name.'* — '.($x['p']->neighborhood?->name ?? '').($x['d'] !== null ? ' ('.number_format($x['d'], 1, ',', '.').' km)' : '');
            $lines[] = '   '.$x['p']->fullAddress();
        }

        return implode("\n", $lines);
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
                $this->reply($customer, "Prazer, {$this->firstName($customer)}! 😊\nQual é o seu *CEP*? (Se não souber, digite *bairro e cidade*, ex.: _Centro, Campinas_)");

                return;

            case 'cep':
                $found = strlen(Text::digits($text)) === 8 ? $this->cep->find($text) : null;
                if ($found) {
                    $city = City::findOrCreateByName($found['city'], $found['uf']);
                    $n = Neighborhood::findByName($found['neighborhood'], $city)
                        ?? Neighborhood::create(['name' => $found['neighborhood'], 'city_id' => $city->id]);
                    $customer->update(['cep' => $found['cep'], 'address' => $found['address'], 'city_id' => $city->id, 'neighborhood_id' => $n->id, 'bot_state' => 'numero']);
                    $this->reply($customer, "📍 {$found['address']} — {$n->name}, {$city->label()}.\nQual o *número* e o *complemento*? (ex.: 120, apto 32)");

                    return;
                }
                if (strlen(Text::digits($text)) === 8) {
                    $this->reply($customer, 'Não encontrei esse CEP. 😕 Digite seu *bairro e cidade*, ex.: _Centro, Campinas_');

                    return;
                }
                $this->registerNeighborhood($customer, $text, $data);

                return;

            case 'cidade':
                $city = City::match($text);
                if (! $city) {
                    $cities = City::query()->where('active', true)->orderBy('name')->limit(15)->get()->map->label()->implode(', ');
                    $this->reply($customer, "Ainda não atendemos essa cidade ou não encontrei o nome. 😕\nCidades atendidas: {$cities}.\nDigite sua *cidade*:");

                    return;
                }
                $this->registerNeighborhood($customer, (string) ($data['bairro'] ?? ''), $data, $city);

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

    /** Bairro digitado (sem CEP): "Centro, Campinas" ou só "Centro" (pergunta a cidade se houver dúvida). */
    private function registerNeighborhood(Customer $customer, string $text, array $data, ?City $city = null): void
    {
        $name = trim($text);
        if (! $city && preg_match('/^(.+?)\s*[,\-\/]\s*(.+)$/u', $text, $m) && ($c = City::match($m[2]))) {
            [$name, $city] = [trim($m[1]), $c];
        }
        if (mb_strlen($name) < 3) {
            $this->reply($customer, 'Digite seu *bairro e cidade*, ex.: _Centro, Campinas_');

            return;
        }
        if (! $city) {
            $same = Neighborhood::allByName($name);
            if ($same->count() === 1 && $same->first()->city_id) {
                $city = City::find($same->first()->city_id);
            } elseif (City::query()->count() === 1) {
                $city = City::first();
            } else {
                $customer->update(['bot_state' => 'cidade', 'bot_data' => ['bairro' => $name] + $data]);
                $this->reply($customer, 'E qual é a sua *cidade*? (ex.: _Campinas_ ou _Campinas/SP_)');

                return;
            }
        }
        $n = Neighborhood::findByName($name, $city);
        if (! $n) {
            $n = Neighborhood::create(['name' => Str::title(mb_substr($name, 0, 80)), 'city_id' => $city?->id]);
            Activity::log('bairro.novo', "Bairro \"{$n->name}\" ({$city?->label()}) criado pelo cadastro do WhatsApp (sem coordenadas)", $n);
        }
        unset($data['bairro']);
        $customer->update(['neighborhood_id' => $n->id, 'city_id' => $city?->id, 'bot_state' => 'endereco', 'bot_data' => $data]);
        $this->reply($customer, "Bairro *{$n->name}*".($city ? " — {$city->label()}" : '')." ✅\nAgora o *endereço para entrega* (rua, número e complemento):");
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
