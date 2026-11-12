<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Receipt;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Captação da venda pela nota fiscal: a IA lê a nota, o sistema identifica a farmácia pelo CNPJ,
 * liga ao pedido/cotação e compara o que foi COTADO com o que foi COMPRADO.
 * A comissão incide sobre tudo o que o cliente comprou (total da nota).
 */
class ReceiptService
{
    public function __construct(private readonly AiAssistant $ai, private readonly LoyaltyService $loyalty, private readonly WhatsAppService $wa) {}

    public function create(Customer $customer, string $bytes, string $mime, string $source = 'whatsapp', ?int $userId = null): Receipt
    {
        $ext = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $path = 'notas/'.now()->format('Y/m').'/'.Str::random(24).'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        $receipt = Receipt::create(['customer_id' => $customer->id, 'file_path' => $path, 'mime' => $mime, 'source' => $source]);
        $this->applyAi($receipt, $bytes, $mime);
        Activity::log('nota.recebida', "Nota {$receipt->code} recebida de {$customer->displayName()} ({$source})", $receipt, userId: $userId);

        return $receipt->fresh(['items', 'pharmacy', 'order']);
    }

    public function applyAi(Receipt $receipt, ?string $bytes = null, ?string $mime = null): void
    {
        $bytes ??= $receipt->file_path ? Storage::disk('local')->get($receipt->file_path) : null;
        if (! $bytes) {
            return;
        }
        $raw = $this->ai->readReceipt($bytes, $mime ?? (string) $receipt->mime);
        if (! $raw) {
            $this->autoLink($receipt);

            return;
        }
        $d = AiAssistant::normalizeReceipt($raw);
        $receipt->fill(['ai_data' => $raw, 'issuer_cnpj' => $d['issuer_cnpj'], 'issuer_name' => $d['issuer_name'], 'nf_number' => $d['nf_number'],
            'nf_key' => $d['nf_key'], 'purchase_date' => $d['purchase_date'], 'total' => $d['total']]);
        if ($d['issuer_cnpj'] && ! $receipt->pharmacy_id) {
            $receipt->pharmacy_id = self::pharmacyByCnpj($d['issuer_cnpj'])?->id;
        }
        $receipt->save();
        $receipt->items()->delete();
        foreach ($d['items'] as $i) {
            $receipt->items()->create($i);
        }
        $this->autoLink($receipt);
    }

    public static function pharmacyByCnpj(?string $cnpj): ?Pharmacy
    {
        $cnpj = Text::digits($cnpj);
        if (strlen($cnpj) !== 14) {
            return null;
        }

        return Pharmacy::query()->get(['id', 'cnpj'])->first(fn ($p) => Text::digits($p->cnpj) === $cnpj);
    }

    /** Liga ao pedido mais recente do cliente (mesma farmácia, se identificada) e compara itens. */
    public function autoLink(Receipt $receipt): void
    {
        if (! $receipt->order_id) {
            $order = Order::query()->where('customer_id', $receipt->customer_id)->where('status', '!=', 'cancelado')
                ->when($receipt->pharmacy_id, fn ($q) => $q->where('pharmacy_id', $receipt->pharmacy_id))
                ->where('created_at', '>=', now()->subDays(15))
                ->whereDoesntHave('receipts', fn ($q) => $q->where('status', 'conferida'))
                ->latest('id')->first();
            if ($order) {
                $receipt->fill(['order_id' => $order->id, 'quote_id' => $order->quote_id, 'pharmacy_id' => $receipt->pharmacy_id ?? $order->pharmacy_id])->save();
            }
        }
        $this->matchItems($receipt->fresh(['items', 'quote.items', 'order.items']));
    }

    /** Para cada item da nota, encontra o item cotado mais parecido e o preço cotado. */
    public function matchItems(Receipt $receipt): void
    {
        $quoteItems = $receipt->quote?->items ?? collect();
        if ($quoteItems->isEmpty()) {
            return;
        }
        $orderItems = $receipt->order?->items ?? collect();
        foreach ($receipt->items as $ri) {
            $best = null;
            $bestScore = 0;
            foreach ($quoteItems as $qi) {
                $score = self::similarity($ri->description, $qi->description.' '.$qi->presentation);
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$qi, $score];
                }
            }
            if ($best && $bestScore >= 0.34) {
                $oi = $orderItems->firstWhere('quote_item_id', $best->id);
                $ri->update(['quote_item_id' => $best->id, 'quoted_unit_price' => $oi?->unit_price]);
            }
        }
    }

    /** Proporção de palavras significativas do item cotado presentes na descrição da nota. */
    public static function similarity(string $a, string $b): float
    {
        $words = fn ($s) => array_values(array_filter(explode(' ', Text::slug($s)), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['com', 'comprimidos', 'caixa', 'cpr', 'com', 'para'], true)));
        $wb = $words($b);
        if (! $wb) {
            return 0;
        }
        $sa = ' '.implode(' ', $words($a)).' ';
        $hits = count(array_filter($wb, fn ($w) => str_contains($sa, ' '.$w) || str_contains($sa, $w)));

        return $hits / count($wb);
    }

    /**
     * Conferência humana: confirma farmácia, total e itens; calcula comissão e pontos.
     *
     * @param  array{pharmacy_id:int, total:mixed, purchase_date?:string, nf_number?:string, order_id?:?int, items?:array}  $data
     */
    public function verify(Receipt $receipt, array $data, ?int $userId = null): Receipt
    {
        $pharmacy = Pharmacy::findOrFail((int) $data['pharmacy_id']);
        $total = Text::money($data['total']) ?? 0;

        abort_unless($pharmacy->active, 422, 'Farmácia não credenciada: compras fora da rede não geram pontos nem comissão.');
        $probe = (clone $receipt)->fill(['pharmacy_id' => $pharmacy->id, 'nf_number' => $data['nf_number'] ?? $receipt->nf_number]);
        $dup = self::duplicates($probe, strict: true)->first();
        if ($dup) {
            throw new \InvalidArgumentException("Nota já lançada ({$dup->code}). A mesma nota não gera pontos nem comissão duas vezes.");
        }

        DB::transaction(function () use ($receipt, $data, $pharmacy, $total, $userId) {
            $receipt->fill([
                'pharmacy_id' => $pharmacy->id, 'total' => $total, 'status' => 'conferida', 'reviewed_by' => $userId, 'reviewed_at' => now(),
                'purchase_date' => $data['purchase_date'] ?? $receipt->purchase_date ?? today(), 'nf_number' => $data['nf_number'] ?? $receipt->nf_number,
                'order_id' => array_key_exists('order_id', $data) ? ($data['order_id'] ?: null) : $receipt->order_id,
            ]);
            if (array_key_exists('items', $data)) {
                $receipt->items()->delete();
                foreach ((array) $data['items'] as $i) {
                    if (filled($i['description'] ?? null)) {
                        $qty = (float) str_replace(',', '.', (string) ($i['quantity'] ?? 1)) ?: 1;
                        $unit = Text::money($i['unit_price'] ?? 0) ?? 0;
                        $receipt->items()->create(['description' => $i['description'], 'quantity' => $qty, 'unit_price' => $unit, 'total' => Text::money($i['total'] ?? null) ?? round($qty * $unit, 2)]);
                    }
                }
            }
            $order = $receipt->order_id ? Order::find($receipt->order_id) : null;
            if ($order) {
                $receipt->quote_id = $order->quote_id;
                // A comissão do pedido passa a usar o total REAL comprado (nota).
                $order->receipt_total = $total;
                $order->recalculateCommission();
                $order->save();
                $receipt->commission_percent = $order->commission_percent;
                $receipt->commission_amount = 0; // já computada no pedido
            } else {
                $receipt->commission_percent = $pharmacy->commission_percent;
                $receipt->commission_amount = round($total * $pharmacy->commission_percent / 100, 2);
            }
            $receipt->save();
        });

        $receipt->load('items', 'customer', 'quote.items', 'order.items');
        $this->matchItems($receipt);
        $points = $this->loyalty->awardForReceipt($receipt, $userId);
        Activity::log('nota.conferida', "Nota {$receipt->code} conferida: {$pharmacy->trade_name} ".money($total), $receipt, ['points' => $points], $userId);

        $msg = "✅ Recebemos e conferimos sua nota fiscal ({$pharmacy->trade_name}, ".money($total).').';
        if ($points > 0) {
            $msg .= "\n🎁 Você ganhou *{$points} pontos*! Saldo: *{$receipt->customer->fresh()->points_balance} pontos*.";
        }
        if (LoyaltyService::viaQuote($receipt) || Setting::get('prize_requires_quote') !== '1') {
            $msg .= "\nVocê está participando da premiação do mês. Boa sorte! 🍀";
        } else {
            $msg .= "\n💡 Da próxima vez, *peça a cotação aqui antes de comprar*: você paga o melhor preço, ganha ".LoyaltyService::quoteIncentive().'!';
        }
        $this->wa->toCustomer($receipt->customer, $msg, $receipt->quote_id);

        return $receipt;
    }

    /**
     * Notas possivelmente repetidas (mesma chave, mesmo nº na mesma farmácia, ou mesmo cliente+farmácia+valor+data).
     * strict = só as que certamente são a mesma nota, entre as já conferidas.
     */
    public static function duplicates(Receipt $r, bool $strict = false): Collection
    {
        return Receipt::query()->where('id', '!=', (int) $r->id)->where('status', $strict ? '=' : '!=', $strict ? 'conferida' : 'rejeitada')
            ->where(function ($q) use ($r, $strict) {
                $any = false;
                if ($r->nf_key) {
                    $q->orWhere('nf_key', $r->nf_key);
                    $any = true;
                }
                if ($r->nf_number && $r->pharmacy_id) {
                    $q->orWhere(fn ($w) => $w->where('pharmacy_id', $r->pharmacy_id)->where('nf_number', $r->nf_number));
                    $any = true;
                }
                if (! $strict && $r->total && $r->pharmacy_id) {
                    $q->orWhere(fn ($w) => $w->where('customer_id', $r->customer_id)->where('pharmacy_id', $r->pharmacy_id)->where('total', $r->total));
                    $any = true;
                }
                if (! $any) {
                    $q->whereRaw('1 = 0');
                }
            })->get();
    }

    /**
     * A farmácia credenciada registra no painel a compra feita no balcão com o cartão fidelidade
     * (sem cotação). Fica pendente de conferência como qualquer nota; o cliente é avisado.
     */
    public function registerCardSale(Pharmacy $pharmacy, Customer $customer, float $total, ?string $nfNumber, ?string $date, ?string $bytes = null, ?string $mime = null, ?int $userId = null): Receipt
    {
        $path = null;
        if ($bytes) {
            $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
            $path = 'notas/'.now()->format('Y/m').'/'.Str::random(24).'.'.$ext;
            Storage::disk('local')->put($path, $bytes);
        }
        $receipt = Receipt::create(['customer_id' => $customer->id, 'pharmacy_id' => $pharmacy->id, 'source' => 'farmacia', 'file_path' => $path, 'mime' => $mime,
            'total' => round($total, 2), 'nf_number' => $nfNumber ?: null, 'purchase_date' => $date ?: today(), 'issuer_cnpj' => Text::digits($pharmacy->cnpj) ?: null, 'issuer_name' => $pharmacy->trade_name]);
        $this->autoLink($receipt);
        Activity::log('nota.farmacia', "{$pharmacy->trade_name} registrou venda do cartão para {$customer->displayName()}: ".money($total), $receipt, userId: $userId);
        $this->wa->toCustomer($customer, "💳 A *{$pharmacy->trade_name}* registrou sua compra de *".money($total).'* no cartão fidelidade Pesquisa Farma. Assim que conferirmos, seus pontos entram! 🎁');

        return $receipt;
    }

    public function reject(Receipt $receipt, string $reason, ?int $userId = null): void
    {
        $receipt->update(['status' => 'rejeitada', 'reject_reason' => $reason, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        $this->wa->toCustomer($receipt->customer, "📄 Não conseguimos validar a nota {$receipt->code}: {$reason}.\nLembre: os pontos valem para compras nas *farmácias credenciadas* (escreva FARMÁCIAS para ver a lista).");
        Activity::log('nota.rejeitada', "Nota {$receipt->code} rejeitada: {$reason}", $receipt, userId: $userId);
    }

    /** Comparativo cotado × comprado de uma nota. */
    public static function comparison(Receipt $receipt): array
    {
        $quoted = (float) ($receipt->order?->total ?? 0);
        $bought = (float) $receipt->total;
        $extra = $receipt->items->whereNull('quote_item_id')->sum('total');

        return ['quoted' => $quoted, 'bought' => $bought, 'difference' => round($bought - $quoted, 2), 'extra_items_total' => round($extra, 2)];
    }
}
