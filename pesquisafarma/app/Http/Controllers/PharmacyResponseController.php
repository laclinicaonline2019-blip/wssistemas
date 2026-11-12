<?php

namespace App\Http\Controllers;

use App\Models\QuotePharmacy;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Formulário de resposta da farmácia aberto direto pelo link do WhatsApp (rápido, sem login). */
class PharmacyResponseController extends Controller
{
    private function find(string $token): QuotePharmacy
    {
        $d = QuotePharmacy::query()->where('token', $token)->with(['quote.items', 'quote.neighborhood', 'pharmacy', 'offers', 'suggestions'])->firstOrFail();
        if (! $d->viewed_at) {
            $d->forceFill(['viewed_at' => now(), 'status' => $d->status === 'pendente' ? 'visualizada' : $d->status])->save();
        }

        return $d;
    }

    public function form(string $token)
    {
        $dispatch = $this->find($token);

        return view('pharmacy.respond', ['dispatch' => $dispatch, 'action' => url('/f/'.$token), 'declineAction' => route('pharmacy.decline', $token), 'layout' => 'layouts.guest']);
    }

    public function save(Request $request, string $token, QuoteService $quotes)
    {
        $dispatch = $this->find($token);
        try {
            $quotes->respond($dispatch, $request->all(), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Resposta enviada! Obrigado. Você pode corrigir os valores enquanto a cotação estiver aberta.');
    }

    public function decline(Request $request, string $token, QuoteService $quotes)
    {
        $quotes->decline($this->find($token), $request->input('reason'));

        return back()->with('ok', 'Registrado: sua farmácia não atende esta cotação.');
    }
}
