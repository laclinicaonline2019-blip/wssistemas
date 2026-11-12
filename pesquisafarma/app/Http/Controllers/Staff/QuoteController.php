<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Neighborhood;
use App\Models\Quote;
use App\Models\QuotePharmacy;
use App\Models\Setting;
use App\Services\AiAssistant;
use App\Services\QuoteService;
use App\Support\Activity;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request)
    {
        $quotes = Quote::query()->with(['customer.neighborhood', 'operator', 'order.pharmacy'])->withCount(['dispatches', 'dispatches as answered_count' => fn ($q) => $q->where('status', 'respondida')])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->operador, fn ($q, $s) => $q->where('operator_id', $s))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))))
            ->latest('id')->paginate(25)->withQueryString();

        return view('staff.quotes.index', compact('quotes'));
    }

    public function create(Request $request)
    {
        return view('staff.quotes.create', ['customer' => $request->cliente ? Customer::find($request->cliente) : null, 'neighborhoods' => Neighborhood::orderBy('name')->get()]);
    }

    /** Cotação aberta pelo televendas (atendimento por telefone/balcão ou cliente novo). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id', 'phone' => 'required_without:customer_id|nullable|string|min:10', 'name' => 'nullable|string|max:120',
            'neighborhood_id' => 'nullable|exists:neighborhoods,id', 'text' => 'nullable|string|max:5000', 'channel' => 'required|in:whatsapp,telefone,balcao,portal',
            'file' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
        ]);
        $customer = $data['customer_id'] ?? null ? Customer::find($data['customer_id'])
            : Customer::query()->firstOrCreate(['phone' => Text::phone($data['phone'])], ['name' => $data['name'] ?? null, 'origin' => $data['channel'], 'neighborhood_id' => $data['neighborhood_id'] ?? null]);
        if (! $customer->neighborhood_id && ($data['neighborhood_id'] ?? null)) {
            $customer->update(['neighborhood_id' => $data['neighborhood_id']]);
        }
        $file = $request->file('file');
        $quote = $this->quotes->createFromMessage($customer, $data['text'] ?? null, $file?->get(), $file?->getMimeType(), $data['channel'], auth()->id());
        $quote->update(['attended_at' => now()]);

        return redirect()->route('quotes.show', $quote)->with('ok', 'Cotação criada. Confira os itens e envie para as farmácias.');
    }

    public function show(Quote $quote)
    {
        $quote->load(['customer.neighborhood', 'items', 'neighborhood', 'operator', 'order.items', 'dispatches.pharmacy.neighborhood', 'dispatches.offers', 'dispatches.suggestions']);
        if (! $quote->operator_id && $quote->isOpen()) {
            $quote->update(['operator_id' => auth()->id(), 'attended_at' => now()]);
            $quote->setRelation('operator', auth()->user());
            Activity::log('cotacao.assumida', "Assumiu a cotação {$quote->code}", $quote);
        }
        $sentIds = $quote->dispatches->pluck('pharmacy_id')->all();

        return view('staff.quotes.show', [
            'quote' => $quote,
            'candidates' => $quote->isOpen() ? $this->quotes->candidates($quote)->reject(fn ($c) => in_array($c['pharmacy']->id, $sentIds, true)) : collect(),
            'far' => $quote->isOpen() ? $this->quotes->candidates($quote, 'ampliada')->reject(fn ($c) => $c['within'] || in_array($c['pharmacy']->id, $sentIds, true)) : collect(),
            'ranking' => $this->quotes->ranking($quote),
            'bestPerItem' => $this->quotes->bestPerItem($quote),
            'preview' => $this->quotes->customerMessage($quote),
            'neighborhoods' => Neighborhood::orderBy('name')->get(),
            'maxPharmacies' => (int) Setting::get('max_pharmacies'),
            'attachmentUrl' => $quote->attachment_path ? route('file', ['cotacao', $quote->id]) : null,
            'timeline' => ActivityLog::query()->where('subject_type', 'Quote')->where('subject_id', $quote->id)->with('user')->latest('id')->get(),
        ]);
    }

    /** Polling da tela: respostas chegando em tempo real. */
    public function status(Quote $quote)
    {
        return response()->json([
            'status' => $quote->status,
            'answered' => $quote->dispatches()->where('status', 'respondida')->count(),
            'pending' => $quote->dispatches()->whereIn('status', ['pendente', 'visualizada'])->count(),
            'signature' => md5((string) $quote->dispatches()->max('updated_at').$quote->updated_at),
        ]);
    }

    public function update(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'neighborhood_id' => 'nullable|exists:neighborhoods,id', 'delivery_address' => 'nullable|string|max:255', 'fulfillment' => 'required|in:entrega,retirada',
            'scope' => 'required|in:proximas,ampliada', 'radius_km' => 'nullable|numeric|min:0.5|max:200', 'notes' => 'nullable|string|max:2000',
        ]);
        $quote->update($data);
        Activity::log('cotacao.editada', "Dados da cotação {$quote->code} atualizados", $quote, $data);

        return back()->with('ok', 'Cotação atualizada.');
    }

    public function saveItems(Request $request, Quote $quote)
    {
        abort_unless($quote->isOpen(), 422);
        $rows = (array) $request->input('items', []);
        $keep = [];
        foreach ($rows as $row) {
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $values = ['description' => mb_substr($desc, 0, 200), 'presentation' => mb_substr(trim((string) ($row['presentation'] ?? '')), 0, 120) ?: null,
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)), 'preference' => in_array($row['preference'] ?? '', ['referencia', 'generico', 'similar'], true) ? $row['preference'] : 'qualquer',
                'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 200) ?: null];
            $item = ! empty($row['id']) ? $quote->items()->find($row['id']) : null;
            $item ? $item->update($values) : $item = $quote->items()->create($values);
            $keep[] = $item->id;
        }
        $quote->items()->whereNotIn('id', $keep)->delete();
        Activity::log('cotacao.itens', "Itens da cotação {$quote->code} revisados (".count($keep).')', $quote);

        return back()->with('ok', 'Itens salvos.');
    }

    public function readAi(Quote $quote, AiAssistant $ai)
    {
        $bytes = $quote->attachment_path ? Storage::disk('local')->get($quote->attachment_path) : null;
        $items = $ai->extractItems($quote->raw_text, $bytes, $quote->attachment_mime);
        foreach ($items as $i) {
            $quote->items()->create($i);
        }
        Activity::log('cotacao.ia', "IA leu a cotação {$quote->code}: ".count($items).' item(ns)', $quote);

        return back()->with(count($items) ? 'ok' : 'error', count($items) ? count($items).' item(ns) incluído(s) pela leitura. Confira!' : 'Nenhum item identificado. Digite os itens manualmente.');
    }

    public function dispatch(Request $request, Quote $quote)
    {
        $ids = array_map('intval', (array) $request->input('pharmacies', []));
        if (! $ids) {
            return back()->with('error', 'Selecione ao menos uma farmácia.');
        }
        try {
            $n = $this->quotes->dispatch($quote, $ids, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', "Cotação enviada para {$n} farmácia(s) pelo WhatsApp.");
    }

    /** Televendas registra a resposta que a farmácia deu por telefone. */
    public function respondFor(Request $request, Quote $quote, QuotePharmacy $dispatch)
    {
        abort_unless($dispatch->quote_id === $quote->id, 404);
        try {
            $request->boolean('decline') ? $this->quotes->decline($dispatch, 'Registrado pelo televendas', auth()->id())
                : $this->quotes->respond($dispatch, $request->all(), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Resposta de '.$dispatch->pharmacy->trade_name.' registrada.');
    }

    public function present(Request $request, Quote $quote)
    {
        if (! $quote->dispatches()->where('status', 'respondida')->exists()) {
            return back()->with('error', 'Ainda não há respostas das farmácias.');
        }
        $this->quotes->present($quote, auth()->id(), (int) $request->input('top', 3));

        return back()->with('ok', 'Melhores opções enviadas ao cliente pelo WhatsApp.');
    }

    public function order(Request $request, Quote $quote)
    {
        $request->validate(['dispatch_id' => 'required|integer', 'fulfillment' => 'nullable|in:entrega,retirada']);
        $dispatch = $quote->dispatches()->findOrFail($request->integer('dispatch_id'));
        $offers = [];
        foreach ((array) $request->input('offer', []) as $itemId => $offerId) {
            $offers[(int) $itemId] = $offerId === '' || $offerId === '0' ? null : (int) $offerId;
        }
        try {
            $order = $this->quotes->createOrder($quote, $dispatch, $offers, array_map('intval', (array) $request->input('suggestions', [])), auth()->id(), $request->input('fulfillment'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('orders.show', $order)->with('ok', "Pedido {$order->code} gerado e enviado à farmácia e ao cliente.");
    }

    public function lost(Request $request, Quote $quote)
    {
        $this->quotes->markLost($quote, $request->validate(['reason' => 'required|string|max:200'])['reason'], auth()->id());

        return back()->with('ok', 'Cotação encerrada como perdida.');
    }

    public function feedback(Request $request, Quote $quote)
    {
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:500']);
        $this->quotes->saveFeedback($quote, $data['rating'], $data['comment'] ?? null);

        return back()->with('ok', 'Avaliação registrada.');
    }
}
