<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WaMessage;
use App\Services\LoyaltyService;
use App\Services\QuoteService;
use App\Services\ReceiptService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Central de conversas do WhatsApp — compartilhada por todos os televendas. */
class InboxController extends Controller
{
    public function index(Request $request)
    {
        $last = WaMessage::query()->whereNotNull('customer_id')->selectRaw('customer_id, MAX(id) as last_id')->groupBy('customer_id');
        $threads = WaMessage::query()->joinSub($last, 'l', 'l.last_id', '=', 'wa_messages.id')
            ->with('customer.neighborhood')
            ->when($request->q, fn ($q, $s) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $s).'%')))
            ->orderByDesc('wa_messages.id')->paginate(30)->withQueryString();
        $unread = WaMessage::query()->where('direction', 'in')->whereNull('read_at')->selectRaw('customer_id, count(*) as n')->groupBy('customer_id')->pluck('n', 'customer_id');

        return view('staff.inbox.index', compact('threads', 'unread'));
    }

    public function show(Customer $customer)
    {
        $customer->messages()->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);

        return view('staff.inbox.show', [
            'customer' => $customer->load('neighborhood'),
            'messages' => $customer->messages()->with('user')->latest('id')->limit(150)->get()->reverse(),
            'quotes' => $customer->quotes()->latest('id')->limit(5)->get(),
            'card' => app(LoyaltyService::class)->card($customer),
        ]);
    }

    public function send(Request $request, Customer $customer, WhatsAppService $wa)
    {
        $data = $request->validate(['text' => 'required|string|max:4000']);
        $wa->toCustomer($customer, $data['text']);
        Activity::log('conversa.mensagem', 'Mensagem enviada para '.$customer->displayName(), $customer);

        return back();
    }

    /** Cliente perguntou os pontos: envia o cartão fidelidade com um clique. */
    public function sendCard(Customer $customer, WhatsAppService $wa, LoyaltyService $loyalty)
    {
        $wa->toCustomer($customer, $loyalty->cardText($customer));
        Activity::log('fidelidade.cartao', 'Cartão fidelidade enviado para '.$customer->displayName(), $customer);

        return back()->with('ok', 'Cartão fidelidade enviado ao cliente.');
    }

    public function toQuote(WaMessage $message, QuoteService $quotes)
    {
        abort_unless($message->customer_id && $message->direction === 'in', 404);
        $bytes = $message->media_path ? Storage::disk('local')->get($message->media_path) : null;
        $quote = $quotes->createFromMessage($message->customer, $message->body, $bytes, $message->mime, 'whatsapp', auth()->id());
        $message->update(['quote_id' => $quote->id]);

        return redirect()->route('quotes.show', $quote)->with('ok', 'Cotação criada a partir da mensagem.');
    }

    public function toReceipt(WaMessage $message, ReceiptService $receipts)
    {
        abort_unless($message->customer_id && $message->media_path, 404);
        $r = $receipts->create($message->customer, Storage::disk('local')->get($message->media_path), (string) $message->mime, 'whatsapp', auth()->id());

        return redirect()->route('receipts.show', $r)->with('ok', 'Nota fiscal criada a partir da mensagem.');
    }
}
