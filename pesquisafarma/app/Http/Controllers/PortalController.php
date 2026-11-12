<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\Reward;
use App\Models\Setting;
use App\Services\LoyaltyService;
use App\Services\QuoteService;
use App\Services\ReceiptService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/** Área do cliente: pontos, histórico, envio de nota fiscal, brindes e promoções. */
class PortalController extends Controller
{
    private function customer(Request $r): Customer
    {
        return $r->attributes->get('customer');
    }

    public function loginForm()
    {
        abort_if(Setting::get('portal_enabled') !== '1', 404);

        return view('portal.login');
    }

    public function sendCode(Request $request, WhatsAppService $wa)
    {
        $request->validate(['phone' => 'required|string|min:10']);
        $customer = Customer::query()->where('phone', Text::phone($request->phone))->first();
        if ($customer) {
            $code = (string) random_int(100000, 999999);
            $customer->update(['portal_code' => Hash::make($code), 'portal_code_expires_at' => now()->addMinutes(10)]);
            $wa->toCustomer($customer, "🔐 Seu código de acesso à área do cliente Pesquisa Farma: *{$code}*\nVálido por 10 minutos. Não compartilhe.");
        }
        $request->session()->put('portal_phone', Text::phone($request->phone));

        return back()->with('code_sent', true)->with('ok', 'Se o número estiver cadastrado, você receberá o código no WhatsApp.');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);
        $customer = Customer::query()->where('phone', $request->session()->get('portal_phone'))->first();
        if (! $customer || ! $customer->portal_code || $customer->portal_code_expires_at?->isPast() || ! Hash::check($request->code, $customer->portal_code)) {
            return back()->with('code_sent', true)->withErrors(['code' => 'Código inválido ou expirado.']);
        }
        $customer->update(['portal_code' => null, 'portal_code_expires_at' => null]);
        $request->session()->regenerate();
        $request->session()->put('customer_id', $customer->id);

        return redirect()->route('portal.home');
    }

    public function home(Request $request, LoyaltyService $loyalty)
    {
        $c = $this->customer($request);

        return view('portal.home', [
            'customer' => $c->load('neighborhood'),
            'quotes' => $c->quotes()->with('order.pharmacy')->latest('id')->limit(10)->get(),
            'receipts' => $c->receipts()->with('pharmacy')->latest('id')->limit(10)->get(),
            'points' => $c->points()->latest('id')->limit(15)->get(),
            'redemptions' => $c->redemptions()->with('reward')->latest('id')->get(),
            'rewards' => $loyalty->enabled($c) ? Reward::query()->where('active', true)->orderBy('points_cost')->get() : collect(),
            'promotions' => Promotion::query()->whereIn('status', ['aprovada', 'divulgada'])->with('pharmacy.neighborhood')->latest('id')->limit(12)->get()->filter->isCurrent(),
            'loyalty' => $loyalty->enabled($c),
        ]);
    }

    public function uploadReceipt(Request $request, ReceiptService $receipts)
    {
        $request->validate(['file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,pdf']);
        $f = $request->file('file');
        $r = $receipts->create($this->customer($request), $f->get(), $f->getMimeType(), 'portal');

        return back()->with('ok', "Nota {$r->code} enviada! Assim que conferirmos, seus pontos serão lançados.");
    }

    public function redeem(Request $request, Reward $reward, LoyaltyService $loyalty)
    {
        try {
            $loyalty->redeem($this->customer($request), $reward);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Troca solicitada! Entraremos em contato para a entrega do brinde. 🎁');
    }

    public function feedback(Request $request, Quote $quote, QuoteService $quotes)
    {
        abort_unless($quote->customer_id === $this->customer($request)->id, 403);
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:500']);
        $quotes->saveFeedback($quote, $data['rating'], $data['comment'] ?? null);

        return back()->with('ok', 'Obrigado pela avaliação!');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('customer_id');

        return redirect()->route('portal.login');
    }
}
