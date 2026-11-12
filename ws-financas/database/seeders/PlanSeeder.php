<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** Planos iniciais — preços, limites e recursos depois são editados no painel do proprietário. Não sobrescreve planos existentes. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'basico', 'name' => 'Básico', 'description' => 'Uma empresa ou finanças pessoais, com as funções financeiras essenciais.',
                'price_monthly_cents' => 2990, 'price_yearly_cents' => 29900, 'max_companies' => 1, 'max_users' => 2, 'whatsapp_messages_month' => 0, 'ai_calls_month' => 0,
                'features' => [], 'sort' => 1],
            ['code' => 'profissional', 'name' => 'Profissional', 'description' => 'Várias empresas, relatórios avançados, visão consolidada e assistente no WhatsApp.',
                'price_monthly_cents' => 6990, 'price_yearly_cents' => 69900, 'max_companies' => 5, 'max_users' => 5, 'whatsapp_messages_month' => 1500, 'ai_calls_month' => 300,
                'features' => ['whatsapp' => true, 'consolidated' => true, 'advanced_reports' => true, 'accountant' => true, 'ai' => true], 'sort' => 2],
            ['code' => 'empresarial', 'name' => 'Empresarial', 'description' => 'Mais usuários, permissões avançadas por perfil e visão consolidada de todo o grupo.',
                'price_monthly_cents' => 14990, 'price_yearly_cents' => 149900, 'max_companies' => 20, 'max_users' => 25, 'whatsapp_messages_month' => 6000, 'ai_calls_month' => 1500,
                'features' => ['whatsapp' => true, 'consolidated' => true, 'advanced_reports' => true, 'advanced_permissions' => true, 'accountant' => true, 'ai' => true], 'sort' => 3],
        ];
        foreach ($plans as $p) {
            Plan::query()->firstOrCreate(['code' => $p['code']], $p + ['active' => true]);
        }
    }
}
