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
        if (\Illuminate\Support\Facades\Schema::hasColumn('plans', 'kind')) {
            self::tiers();
        }
    }

    /**
     * 1.1.7 — planos por quantidade de empresas (o CPF do titular não conta no limite). Criados INATIVOS e com preço
     * zero: o proprietário define preços/limites e ativa em Proprietário → Planos. Nunca altera planos existentes.
     */
    public static function tiers(): void
    {
        $all = ['whatsapp' => true, 'consolidated' => true, 'advanced_reports' => true, 'advanced_permissions' => true, 'accountant' => true, 'ai' => true];
        $plans = [
            ['pessoal', 'Pessoal (CPF)', 'Somente as finanças pessoais do titular (CPF), com assistente no WhatsApp e relatórios.', 'personal', 0, 1, 1500, 300,
                ['whatsapp' => true, 'advanced_reports' => true, 'ai' => true], 10],
            ['ate3', 'Até 3 empresas', 'CPF do titular + até 3 empresas (CNPJ).', 'business', 3, 5, 3000, 600, $all, 11],
            ['ate8', 'De 4 a 8 empresas', 'CPF do titular + até 8 empresas (CNPJ).', 'business', 8, 10, 6000, 1200, $all, 12],
            ['ate15', 'De 9 a 15 empresas', 'CPF do titular + até 15 empresas (CNPJ).', 'business', 15, 20, 10000, 2000, $all, 13],
            ['ate25', 'De 16 a 25 empresas', 'CPF do titular + até 25 empresas (CNPJ).', 'business', 25, 30, 15000, 3000, $all, 14],
            ['ate50', 'De 26 a 50 empresas', 'CPF do titular + até 50 empresas (CNPJ).', 'business', 50, 60, 25000, 5000, $all, 15],
            ['personalizado', 'Personalizado (acima de 50)', 'Acima de 50 empresas: condições sob consulta.', 'custom', 9999, 9999, 0, 0, $all, 16],
        ];
        foreach ($plans as [$code, $name, $desc, $kind, $maxC, $maxU, $wa, $ai, $features, $sort]) {
            Plan::query()->firstOrCreate(['code' => $code], ['name' => $name, 'description' => $desc, 'kind' => $kind, 'price_monthly_cents' => 0, 'price_yearly_cents' => 0,
                'max_companies' => $maxC, 'max_users' => $maxU, 'whatsapp_messages_month' => $wa, 'ai_calls_month' => $ai, 'features' => $features, 'active' => false, 'sort' => $sort]);
        }
    }
}
