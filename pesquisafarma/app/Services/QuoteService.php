<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteOffer;
use App\Models\QuotePharmacy;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Geo;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fluxo completo da cotação:
 * recebida (WhatsApp/telefone) → enviada às farmácias próximas → respostas → ranking do melhor preço
 * → apresentada ao cliente → pedido (venda computada) → finalizada → avaliação do cliente.
 */
class QuoteService
{
    public function __construct(private readonly AiAssistant $ai, private readonly WhatsAppService $wa, private readonly FollowUpService $followUp) {}

    // ------------------------------------------------------------------ Entrada

    public function createFromMessage(Customer $customer, ?string $text, ?string $bytes = null, ?string $mime = null, string $channel = 'whatsapp', ?int $operatorId = null): Quote
    {
        $quote = Quote::create([
            'customer_id' => $customer->id,
            'operator_id' => $operatorId,
            'channel' => $channel,
            'raw_text' => $text,
            'neighborhood_id' => $customer->neighborhood_id,
            'delivery_address' => $customer->fullAddress() ?: null,
            'scope' => 'proximas',
            'radius_km' => (float) Setting::get('radius_near_km'),
        ]);
        if ($bytes) {
            $this->storeAttachment($quote, $bytes, $mime);
        }
        $this->addItemsFromMessage($quote, $text, $bytes, $mime);
        Activity::log('cotacao.recebida', "Cotação {$quote->code} recebida de {$customer->displayName()} ({$channel})", $quote, userId: $operatorId);

        return $quote;
    }

    /** Mensagens seguidas do cliente (ex.: texto + foto) entram na mesma cotação ainda não enviada. */
    public function openDraft(Customer $customer): ?Quote
    {
        return $customer->quotes()->where('status', 'recebida')->where('updated_at', '>=', now()->subMinutes(30))->latest('id')->first();
    }

    public function appendMessage(Quote $quote, ?string $text, ?string $bytes = null, ?string $mime = null): void
    {
        if ($text) {
            $quote->raw_text = trim(($quote->raw_text ? $quote->raw_text."\n" : '').$text);
        }
        if ($bytes && ! $quote->attachment_path) {
            $this->storeAttachment($quote, $bytes, $mime);
        }
        $quote->save();
        $this->addItemsFromMessage($quote, $text, $bytes, $mime);
    }

    private function storeAttachment(Quote $quote, string $bytes, ?string $mime): void
    {
        $ext = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $path = 'cotacoes/'.$quote->code.'-'.Str::random(6).'.'.$ext;
        Storage::disk('local')->put($path, $bytes);
        $quote->forceFill(['attachment_path' => $path, 'attachment_mime' => $mime ?: 'image/jpeg'])->save();
    }

    private function addItemsFromMessage(Quote $quote, ?string $text, ?string $bytes, ?string $mime): void
    {
        $items = $this->ai->extractItems($text, $bytes, $mime);
        foreach ($items as $i) {
            $quote->items()->create($i);
        }
        if ($items && $this->ai->enabled()) {
            $quote->forceFill(['ai_extracted' => true])->save();
        }
    }

    // ------------------------------------------------------------------ Farmácias candidatas

    /**
     * Farmácias para a cotação, da mais próxima à mais distante.
     * "proximas": dentro do raio curto, do mesmo bairro ou que atendem o bairro (zona de entrega).
     * "ampliada": até o raio longo — para o cliente avaliar se vale a pena ir mais longe.
     *
     * @return Collection<int, array{pharmacy:Pharmacy, distance:?float, within:bool, delivers:bool, fee:?float, eta:?int}>
     */
    public function candidates(Quote $quote, ?string $scope = null, ?float $radius = null): Collection
    {
        $scope ??= $quote->scope;
        $nearRadius = $quote->radius_km ?: (float) Setting::get('radius_near_km');
        $maxRadius = $radius ?? ($scope === 'ampliada' ? max($nearRadius, (float) Setting::get('radius_far_km')) : $nearRadius);
        $origin = $this->origin($quote);
        $cityId = $quote->neighborhood?->city_id ?? $quote->customer?->city_id;

        return Pharmacy::query()->where('active', true)->with(['neighborhood', 'zones'])->get()
            ->map(function (Pharmacy $p) use ($quote, $origin, $nearRadius) {
                $distance = Geo::distanceKm($origin, ($p->lat && $p->lng) ? [$p->lat, $p->lng] : null);
                $sameArea = $quote->neighborhood_id && ($p->neighborhood_id === $quote->neighborhood_id || $p->zones->contains('neighborhood_id', $quote->neighborhood_id));
                $within = $sameArea || ($distance !== null && $distance <= $nearRadius);
                [$delivers, $fee, $eta] = $this->delivery($p, $quote->neighborhood_id, $distance);

                return ['pharmacy' => $p, 'distance' => $distance, 'within' => $within, 'same_area' => $sameArea, 'delivers' => $delivers, 'fee' => $fee, 'eta' => $eta];
            })
            // Sem coordenadas, só entra farmácia da mesma cidade (o sistema atende vários municípios/estados).
            ->filter(fn ($c) => $c['within'] || ($c['distance'] !== null && $c['distance'] <= $maxRadius)
                || ($scope === 'ampliada' && $c['distance'] === null && $cityId && $c['pharmacy']->city_id === $cityId))
            ->sortBy(fn ($c) => [$c['same_area'] ? 0 : 1, $c['distance'] ?? 9999])
            ->values();
    }

    private function origin(Quote $quote): ?array
    {
        $customer = $quote->customer;
        if ($customer && $customer->neighborhood_id === $quote->neighborhood_id && $customer->coordinates()) {
            return $customer->coordinates();
        }
        $n = $quote->neighborhood;

        return ($n && $n->lat && $n->lng) ? [$n->lat, $n->lng] : $customer?->coordinates();
    }

    /** @return array{0:bool, 1:?float, 2:?int} entrega? taxa, prazo (min) */
    public function delivery(Pharmacy $p, ?int $neighborhoodId, ?float $distance): array
    {
        if (! $p->delivers) {
            return [false, null, null];
        }
        $zone = $neighborhoodId ? $p->zones->firstWhere('neighborhood_id', $neighborhoodId) : null;
        if ($zone) {
            return [true, (float) $zone->fee, $zone->eta_min ?? $p->delivery_eta_min];
        }
        if ($p->neighborhood_id && $p->neighborhood_id === $neighborhoodId) {
            return [true, round($p->delivery_base_fee, 2), $p->delivery_eta_min];
        }
        if ($distance !== null && $distance <= $p->delivery_radius_km) {
            return [true, round($p->delivery_base_fee + $p->delivery_fee_per_km * $distance, 2), $p->delivery_eta_min];
        }

        return [false, null, null];
    }

    // ------------------------------------------------------------------ Envio às farmácias

    /** @param array<int> $pharmacyIds */
    public function dispatch(Quote $quote, array $pharmacyIds, ?int $operatorId = null): int
    {
        if ($quote->items()->count() === 0) {
            throw new InvalidArgumentException('Inclua ao menos um item antes de enviar a cotação.');
        }
        $byId = $this->candidates($quote, 'ampliada', 99999)->keyBy(fn ($c) => $c['pharmacy']->id);
        $minutes = (int) Setting::get('response_minutes');
        $sent = 0;

        foreach (array_unique(array_map('intval', $pharmacyIds)) as $id) {
            $c = $byId[$id] ?? null;
            if (! $c || $quote->dispatches()->where('pharmacy_id', $id)->exists()) {
                continue;
            }
            $dispatch = $quote->dispatches()->create([
                'pharmacy_id' => $id, 'token' => Str::random(48), 'distance_km' => $c['distance'], 'within_radius' => $c['within'],
                'delivers' => $c['delivers'], 'delivery_fee' => $c['fee'], 'delivery_eta_min' => $c['eta'], 'sent_at' => now(),
            ]);
            $this->wa->toPharmacy($c['pharmacy'], $this->pharmacyMessage($quote, $dispatch, $minutes), $quote->id);
            $sent++;
        }

        if ($sent > 0) {
            $quote->forceFill([
                'status' => in_array($quote->status, ['recebida'], true) ? 'enviada' : $quote->status,
                'operator_id' => $quote->operator_id ?? $operatorId,
                'attended_at' => $quote->attended_at ?? now(),
                'dispatched_at' => $quote->dispatched_at ?? now(),
                'response_deadline_at' => now()->addMinutes($minutes),
            ])->save();
            Activity::log('cotacao.enviada', "Cotação {$quote->code} enviada para {$sent} farmácia(s)", $quote, ['pharmacies' => $pharmacyIds], $operatorId);
        }

        return $sent;
    }

    private function pharmacyMessage(Quote $quote, QuotePharmacy $d, int $minutes): string
    {
        $lines = ["💊 *Nova cotação Pesquisa Farma* — {$quote->code}", ''];
        foreach ($quote->items as $item) {
            $lines[] = '• '.$item->label().($item->preference !== 'qualquer' ? ' ('.QuoteItem::PREFERENCES[$item->preference].')' : '');
        }
        $lines[] = '';
        $lines[] = '📍 Bairro do cliente: '.($quote->neighborhood?->name ?? 'não informado').($d->distance_km !== null ? ' (≈ '.number_format($d->distance_km, 1, ',', '.').' km)' : '');
        $lines[] = $quote->fulfillment === 'entrega' ? '🛵 Cliente quer *entrega*' : '🏪 Cliente vai *retirar*';
        $lines[] = "⏱️ Responda em até {$minutes} min:";
        $lines[] = route('pharmacy.respond', $d->token);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ Resposta da farmácia

    /**
     * @param  array{offers: array<int, array<int, array{type:string, price:mixed, product_name?:string, laboratory?:string}>>,
     *         delivers?:bool, delivery_fee?:mixed, delivery_eta_min?:mixed, payment_methods?:string, notes?:string,
     *         suggestions?: array<int, array{product_name?:string, description?:string, price?:mixed, original_price?:mixed}>}  $data
     */
    public function respond(QuotePharmacy $dispatch, array $data, ?int $userId = null): void
    {
        if (! $dispatch->quote->isOpen()) {
            throw new InvalidArgumentException('Esta cotação já foi encerrada.');
        }
        DB::transaction(function () use ($dispatch, $data, $userId) {
            $dispatch->offers()->delete();
            $dispatch->suggestions()->delete();
            $itemIds = $dispatch->quote->items()->pluck('id')->all();

            foreach ((array) ($data['offers'] ?? []) as $itemId => $lines) {
                if (! in_array((int) $itemId, $itemIds, true)) {
                    continue;
                }
                foreach ((array) $lines as $line) {
                    $price = Text::money($line['price'] ?? null);
                    $type = $line['type'] ?? '';
                    if (! $price || $price <= 0 || ! array_key_exists($type, QuoteOffer::TYPES)) {
                        continue;
                    }
                    $dispatch->offers()->create([
                        'quote_item_id' => (int) $itemId, 'type' => $type, 'unit_price' => $price,
                        'product_name' => trim((string) ($line['product_name'] ?? '')) ?: null,
                        'laboratory' => trim((string) ($line['laboratory'] ?? '')) ?: null,
                    ]);
                }
            }
            foreach ((array) ($data['suggestions'] ?? []) as $s) {
                $price = Text::money($s['price'] ?? null);
                if (filled($s['product_name'] ?? null) && $price) {
                    $dispatch->suggestions()->create([
                        'product_name' => trim($s['product_name']), 'description' => trim((string) ($s['description'] ?? '')) ?: null,
                        'price' => $price, 'original_price' => Text::money($s['original_price'] ?? null),
                    ]);
                }
            }

            $delivers = (bool) ($data['delivers'] ?? $dispatch->delivers);
            $dispatch->fill([
                'status' => $dispatch->offers()->exists() ? 'respondida' : 'recusada',
                'responded_at' => now(),
                'responded_by' => $userId,
                'delivers' => $delivers,
                'delivery_fee' => $delivers ? (Text::money($data['delivery_fee'] ?? null) ?? $dispatch->delivery_fee ?? 0) : null,
                'delivery_eta_min' => $delivers ? ((int) ($data['delivery_eta_min'] ?? 0) ?: $dispatch->delivery_eta_min) : null,
                'payment_methods' => $data['payment_methods'] ?? $dispatch->payment_methods,
                'notes' => $data['notes'] ?? null,
            ])->save();

            $quote = $dispatch->quote;
            $quote->first_response_at ??= now();
            if ($quote->status === 'enviada') {
                $quote->status = 'respondida';
            }
            $quote->save();
        });

        $this->ranking($dispatch->quote->fresh());
        Activity::log('cotacao.resposta', "{$dispatch->pharmacy->trade_name} respondeu a cotação {$dispatch->quote->code}", $dispatch->quote,
            ['dispatch' => $dispatch->id, 'status' => $dispatch->status], $userId);
    }

    public function decline(QuotePharmacy $dispatch, ?string $reason = null, ?int $userId = null): void
    {
        $dispatch->offers()->delete();
        $dispatch->update(['status' => 'recusada', 'responded_at' => now(), 'responded_by' => $userId, 'notes' => $reason]);
        Activity::log('cotacao.recusa', "{$dispatch->pharmacy->trade_name} não atende a cotação {$dispatch->quote->code}", $dispatch->quote, userId: $userId);
    }

    // ------------------------------------------------------------------ Melhor preço

    /** Melhor oferta da farmácia para o item, respeitando a preferência do cliente (genérico, ético...). */
    public static function bestOffer(Collection $offers, QuoteItem $item): ?QuoteOffer
    {
        return $offers->where('quote_item_id', $item->id)->where('available', true)
            ->when($item->preference !== 'qualquer', fn ($c) => $c->where('type', $item->preference))
            ->sortBy('unit_price')->first();
    }

    /**
     * Ranking geral: mais itens da cotação; se o cliente quer entrega, quem entrega; depois o menor total (itens + entrega).
     *
     * @return Collection<int, array{dispatch:QuotePharmacy, lines:array, items_available:int, subtotal:float, delivery:float, total:float, complete:bool, rank:int}>
     */
    public function ranking(Quote $quote): Collection
    {
        $quote->loadMissing('items');
        $total = $quote->items->count();
        $rows = $quote->dispatches()->where('status', 'respondida')->with(['pharmacy.neighborhood', 'offers', 'suggestions'])->get()
            ->map(function (QuotePharmacy $d) use ($quote) {
                $lines = [];
                $subtotal = 0.0;
                foreach ($quote->items as $item) {
                    $best = self::bestOffer($d->offers, $item);
                    $lines[$item->id] = $best;
                    if ($best) {
                        $subtotal += $best->unit_price * $item->quantity;
                    }
                }
                $available = count(array_filter($lines));
                $delivery = ($quote->fulfillment === 'entrega' && $d->delivers) ? (float) $d->delivery_fee : 0.0;
                if ($delivery > 0 && $d->pharmacy->free_delivery_above && $subtotal >= $d->pharmacy->free_delivery_above) {
                    $delivery = 0.0;
                }

                return ['dispatch' => $d, 'lines' => $lines, 'items_available' => $available, 'subtotal' => round($subtotal, 2),
                    'delivery' => round($delivery, 2), 'total' => round($subtotal + $delivery, 2)];
            })
            // Cliente quer entrega: quem entrega vem antes de quem só tem retirada.
            ->sortBy(fn ($r) => [-$r['items_available'], ($quote->fulfillment === 'entrega' && ! $r['dispatch']->delivers) ? 1 : 0, $r['total']])
            ->values()
            ->map(function ($r, $i) use ($total) {
                $r['rank'] = $i + 1;
                $r['complete'] = $r['items_available'] === $total;
                $r['dispatch']->forceFill(['rank' => $i + 1, 'subtotal' => $r['subtotal'], 'total' => $r['total'], 'items_available' => $r['items_available']])->saveQuietly();

                return $r;
            });

        return $rows;
    }

    /** Cesta ideal: o menor preço de cada item entre todas as farmácias (referência de economia). */
    public function bestPerItem(Quote $quote): array
    {
        $out = [];
        $dispatches = $quote->dispatches()->where('status', 'respondida')->with(['pharmacy', 'offers'])->get();
        foreach ($quote->items as $item) {
            $best = null;
            foreach ($dispatches as $d) {
                $o = self::bestOffer($d->offers, $item);
                if ($o && (! $best || $o->unit_price < $best['offer']->unit_price)) {
                    $best = ['offer' => $o, 'pharmacy' => $d->pharmacy];
                }
            }
            $out[$item->id] = $best;
        }

        return $out;
    }

    /** Texto do WhatsApp com as melhores opções (por ordem), bairro, distância e entrega. */
    public function customerMessage(Quote $quote, int $top = 3): string
    {
        $ranking = $this->ranking($quote)->take($top);
        $n = $quote->items->count();
        $lines = ['💊 *Sua cotação '.$quote->code.' está pronta!*', 'Pesquisamos em '.$quote->dispatches()->count().' farmácias. As melhores opções:', ''];
        $medals = ['🥇', '🥈', '🥉'];
        foreach ($ranking as $i => $r) {
            $d = $r['dispatch'];
            $p = $d->pharmacy;
            $lines[] = ($medals[$i] ?? ($i + 1).'º').' *'.$p->trade_name.'* — '.($p->neighborhood?->name ?? '').($d->distance_km !== null ? ' ('.number_format($d->distance_km, 1, ',', '.').' km)' : '');
            foreach ($quote->items as $item) {
                $o = $r['lines'][$item->id] ?? null;
                $lines[] = $o
                    ? '   • '.$item->quantity.'x '.($o->product_name ?: $item->description).' ('.$o->typeLabel().') '.money($o->unit_price * $item->quantity)
                    : '   • '.$item->description.': _não tem_';
            }
            if ($quote->fulfillment === 'entrega') {
                $lines[] = $d->delivers ? '   🛵 Entrega: '.($r['delivery'] > 0 ? money($r['delivery']) : 'GRÁTIS').($d->delivery_eta_min ? ' · ~'.$d->delivery_eta_min.' min' : '') : '   🏪 Não entrega no seu endereço (só retirada)';
            }
            $lines[] = '   *Total: '.money($r['total']).'*'.($r['complete'] ? '' : " ({$r['items_available']} de {$n} itens)");
            foreach ($d->suggestions as $s) {
                $lines[] = '   🎁 Oferta: '.$s->product_name.' por '.money($s->price).($s->original_price ? ' (de '.money($s->original_price).')' : '');
            }
            $lines[] = '   📍 '.$p->fullAddress();
            $lines[] = '';
        }
        if ($ranking->isEmpty()) {
            $lines[] = 'Ainda não recebemos respostas. Assim que chegarem, avisamos! 🙏';
        } else {
            $lines[] = 'Responda com o *número da opção* (1, 2 ou 3) para fazer o pedido. 😉';
        }

        return implode("\n", $lines);
    }

    public function present(Quote $quote, ?int $userId = null, int $top = 3): void
    {
        $this->wa->toCustomer($quote->customer, $this->customerMessage($quote, $top), $quote->id);
        $quote->forceFill(['status' => in_array($quote->status, ['enviada', 'respondida', 'apresentada'], true) ? 'apresentada' : $quote->status, 'presented_at' => $quote->presented_at ?? now()])->save();
        $quote->customer->update(['bot_state' => 'escolha', 'bot_data' => ['quote_id' => $quote->id]]);
        if (! $quote->followup_at && $quote->followup_count === 0) {
            $this->followUp->scheduleForQuote($quote); // sem resposta? perguntamos se comprou
        }
        Activity::log('cotacao.apresentada', "Cotação {$quote->code} apresentada ao cliente", $quote, userId: $userId);
    }

    // ------------------------------------------------------------------ Pedido

    /**
     * Gera o pedido na farmácia escolhida (venda computada para comissão).
     *
     * @param  array<int, int|null>  $offerIds  item_id => offer_id (null = item fora do pedido); vazio = melhor de cada item
     * @param  array<int>  $suggestionIds  promoções aceitas pelo cliente
     */
    public function createOrder(Quote $quote, QuotePharmacy $dispatch, array $offerIds = [], array $suggestionIds = [], ?int $userId = null, ?string $fulfillment = null): Order
    {
        if ($dispatch->quote_id !== $quote->id || $dispatch->status !== 'respondida') {
            throw new InvalidArgumentException('Escolha uma farmácia que respondeu esta cotação.');
        }
        if ($quote->order && $quote->order->status !== 'cancelado') {
            throw new InvalidArgumentException('Esta cotação já tem pedido.');
        }
        $fulfillment ??= $quote->fulfillment;
        if ($fulfillment === 'entrega' && ! $dispatch->delivers) {
            $fulfillment = 'retirada';
        }

        $order = DB::transaction(function () use ($quote, $dispatch, $offerIds, $suggestionIds, $userId, $fulfillment) {
            $pharmacy = $dispatch->pharmacy;
            $order = Order::create([
                'quote_id' => $quote->id, 'customer_id' => $quote->customer_id, 'pharmacy_id' => $pharmacy->id,
                'operator_id' => $userId ?? $quote->operator_id, 'fulfillment' => $fulfillment, 'commission_percent' => $pharmacy->commission_percent,
            ]);
            $subtotal = 0.0;
            foreach ($quote->items as $item) {
                $offer = array_key_exists($item->id, $offerIds)
                    ? ($offerIds[$item->id] ? $dispatch->offers()->find($offerIds[$item->id]) : null)
                    : self::bestOffer($dispatch->offers, $item);
                if (! $offer || $offer->quote_item_id !== $item->id) {
                    continue;
                }
                $line = round($offer->unit_price * $item->quantity, 2);
                $subtotal += $line;
                $order->items()->create(['quote_item_id' => $item->id, 'description' => ($offer->product_name ?: $item->description).' ('.$offer->typeLabel().')',
                    'type' => $offer->type, 'quantity' => $item->quantity, 'unit_price' => $offer->unit_price, 'total' => $line]);
            }
            foreach ($dispatch->suggestions()->whereIn('id', $suggestionIds)->get() as $s) {
                $s->update(['accepted' => true]);
                $subtotal += $s->price;
                $order->items()->create(['description' => $s->product_name.' (promoção)', 'quantity' => 1, 'unit_price' => $s->price, 'total' => $s->price, 'is_suggestion' => true]);
            }
            if ($order->items()->count() === 0) {
                throw new InvalidArgumentException('Nenhum item selecionado para o pedido.');
            }
            $delivery = $fulfillment === 'entrega' ? (float) $dispatch->delivery_fee : 0.0;
            if ($delivery > 0 && $pharmacy->free_delivery_above && $subtotal >= $pharmacy->free_delivery_above) {
                $delivery = 0.0;
            }
            $order->fill(['subtotal' => round($subtotal, 2), 'delivery_fee' => round($delivery, 2), 'total' => round($subtotal + $delivery, 2)]);
            $order->recalculateCommission();
            $order->save();
            $quote->update(['status' => 'pedido', 'fulfillment' => $fulfillment]);

            return $order;
        });

        $order->load('items', 'customer', 'pharmacy.neighborhood');
        $quote->forceFill(['followup_at' => null])->save();
        $this->followUp->scheduleForOrder($order, $dispatch->delivery_eta_min); // pós-venda: "seu pedido chegou?"
        $this->wa->toPharmacy($order->pharmacy, $this->orderMessageForPharmacy($order), $quote->id);
        $this->wa->toCustomer($order->customer, $this->orderMessageForCustomer($order), $quote->id);
        $order->customer->update(['bot_state' => null, 'bot_data' => null]);
        Activity::log('pedido.criado', "Pedido {$order->code} ({$order->pharmacy->trade_name}) ".money($order->total), $order, ['quote' => $quote->code], $userId);

        return $order;
    }

    private function orderMessageForPharmacy(Order $o): string
    {
        $c = $o->customer;
        $lines = ["✅ *Pedido confirmado Pesquisa Farma* — {$o->code}", ''];
        foreach ($o->items as $i) {
            $lines[] = '• '.$i->quantity.'x '.$i->description.' — '.money($i->total);
        }
        $lines[] = '';
        $lines[] = ($o->fulfillment === 'entrega' ? '🛵 Entrega: '.money($o->delivery_fee) : '🏪 Retirada na loja');
        $lines[] = '*Total: '.money($o->total).'*';
        $lines[] = '';
        $lines[] = '👤 '.$c->displayName().' · '.Text::formatPhone($c->phone);
        if ($o->fulfillment === 'entrega') {
            $lines[] = '📍 '.($o->quote?->delivery_address ?: $c->fullAddress());
        }

        return implode("\n", $lines);
    }

    private function orderMessageForCustomer(Order $o): string
    {
        $p = $o->pharmacy;
        $lines = ['✅ *Pedido '.$o->code.' confirmado!*', '🏪 '.$p->trade_name, '📍 '.$p->fullAddress(), '🗺️ '.$p->mapsUrl()];
        if ($p->phone) {
            $lines[] = '📞 '.Text::formatPhone($p->phone);
        }
        $lines[] = $o->fulfillment === 'entrega' ? '🛵 A farmácia vai entregar no seu endereço.' : '🏪 Seu pedido estará separado para retirada.';
        $lines[] = '*Total: '.money($o->total).'*';
        $lines[] = '';
        $lines[] = '📸 Ao receber, envie a *foto da nota fiscal* aqui e ganhe pontos para trocar por brindes e concorrer à premiação do mês! 🎁';

        return implode("\n", $lines);
    }

    /** Farmácia ou televendas marcou como entregue/retirado: encerra e já faz a pesquisa de indicação. */
    public function completeOrder(Order $order, ?int $userId = null): void
    {
        $order->update(['status' => 'entregue', 'completed_at' => now(), 'followup_at' => null]);
        if ($order->quote) {
            $this->finish($order->quote, $userId, $order);
        }
        Activity::log('pedido.entregue', "Pedido {$order->code} entregue/retirado", $order, userId: $userId);
    }

    public function cancelOrder(Order $order, ?int $userId = null): void
    {
        $order->status = 'cancelado';
        $order->cancelled_at = now();
        $order->followup_at = null;
        $order->recalculateCommission();
        $order->save();
        $order->quote?->update(['status' => 'respondida']);
        Activity::log('pedido.cancelado', "Pedido {$order->code} cancelado", $order, userId: $userId);
    }

    /** Encerra a cotação e inicia o pós-venda: indicação (0 a 10) e depois o pedido da nota fiscal. */
    public function finish(Quote $quote, ?int $userId = null, ?Order $order = null): void
    {
        $quote->update(['status' => 'finalizada', 'closed_at' => $quote->closed_at ?? now(), 'followup_at' => null]);
        if ($quote->nps_score === null) {
            // Entrega já confirmada pela farmácia/televendas: pesquisa só com indicação + nota fiscal.
            $this->followUp->askPostSale($quote->customer, $quote, $order ?? $quote->order, askDelivery: false);
        }
        Activity::log('cotacao.finalizada', "Cotação {$quote->code} finalizada", $quote, userId: $userId);
    }

    public function markLost(Quote $quote, string $reason, ?int $userId = null): void
    {
        $quote->update(['status' => 'perdida', 'lost_reason' => $reason, 'closed_at' => now(), 'followup_at' => null]);
        Activity::log('cotacao.perdida', "Cotação {$quote->code} perdida: {$reason}", $quote, userId: $userId);
    }

    /** Indicação: "de 0 a 10, quanto indicaria a Pesquisa Farma?" */
    public function saveNps(Quote $quote, int $score): void
    {
        $score = max(0, min(10, $score));
        $quote->update(['nps_score' => $score, 'nps_at' => now()]);
        Activity::log('cotacao.indicacao', "Cliente deu nota {$score} de indicação (cotação {$quote->code})", $quote, ['grupo' => Quote::npsGroup($score)]);
    }

    public function saveFeedback(Quote $quote, int $rating, ?string $comment = null): void
    {
        $quote->update(['feedback_rating' => max(1, min(5, $rating)), 'feedback_comment' => $comment, 'feedback_at' => now()]);
        Activity::log('cotacao.avaliacao', "Cliente avaliou a cotação {$quote->code} com nota {$rating}", $quote);
    }

    /** Marca como "sem resposta" as farmácias que passaram do prazo. */
    public function expireOverdue(): int
    {
        return QuotePharmacy::query()->whereIn('status', ['pendente', 'visualizada'])
            ->whereHas('quote', fn ($q) => $q->whereNotNull('response_deadline_at')->where('response_deadline_at', '<', now()->subMinutes(30)))
            ->update(['status' => 'expirada']);
    }
}
