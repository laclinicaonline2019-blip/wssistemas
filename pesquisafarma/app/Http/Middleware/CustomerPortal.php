<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/** Sessão da área do cliente (login por código enviado no WhatsApp). */
class CustomerPortal
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(Setting::get('portal_enabled') !== '1', 404);
        $customer = Customer::find($request->session()->get('customer_id'));
        if (! $customer) {
            return redirect()->route('portal.login');
        }
        $request->attributes->set('customer', $customer);

        return $next($request);
    }
}
