<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsappContact;
use App\Services\AccountService;
use App\Services\FinanceService;
use Illuminate\Database\Seeder;

/** Dados fictícios para demonstração/homologação. Nunca rode em produção. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);
        User::query()->firstOrCreate(['email' => 'dono@demo.wsfinancas.local'], ['name' => 'Proprietário (demo)', 'password' => 'Demo@12345', 'is_platform_admin' => true]);
        if (User::query()->where('email', 'cliente@demo.wsfinancas.local')->exists()) {
            return;
        }
        $ctx = app(TenantContext::class);
        [$account, , $company] = app(AccountService::class)->register(['name' => 'Comercial Exemplo Ltda', 'person_type' => 'pj', 'email' => 'cliente@demo.wsfinancas.local',
            'company_name' => 'Loja Centro', 'admin_name' => 'Cliente Demo', 'admin_email' => 'cliente@demo.wsfinancas.local', 'password' => 'Demo@12345'],
            Plan::query()->where('code', 'empresarial')->first(), 'monthly', 30);
        $ctx->runFor($account->id, function () use ($company) {
            $company->update(['alias' => 'A']);
            $b = app(AccountService::class)->createCompany(['name' => 'Filial Bairro', 'alias' => 'B', 'person_type' => 'pj']);
            app(FinanceService::class)->loadSample($company);
            WhatsappContact::query()->create(['label' => 'Celular do dono (demo)', 'phone' => '5511999990000', 'phone_hash' => WhatsappContact::hashPhone('5511999990000'),
                'phone_last4' => '0000', 'default_company_id' => $company->id, 'can_write' => true]);
        });
    }
}
