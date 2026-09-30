<?php

namespace Database\Seeders;

use App\Modules\Platform\Models\SaasPlan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'essencial', 'name' => 'Essencial', 'price_monthly_cents' => 19900, 'price_yearly_cents' => 199000, 'trial_days' => 14,
                'limits' => ['max_users' => 5, 'max_branches' => 1, 'max_doctors' => 3, 'storage_mb' => 5120, 'ai_enabled' => false, 'whatsapp_enabled' => false]],
            ['code' => 'profissional', 'name' => 'Profissional', 'price_monthly_cents' => 49900, 'price_yearly_cents' => 499000, 'trial_days' => 14,
                'limits' => ['max_users' => 20, 'max_branches' => 3, 'max_doctors' => 15, 'storage_mb' => 51200, 'ai_enabled' => true, 'whatsapp_enabled' => true]],
            ['code' => 'rede', 'name' => 'Rede', 'price_monthly_cents' => 129900, 'price_yearly_cents' => 1299000, 'trial_days' => 14,
                'limits' => ['max_users' => null, 'max_branches' => null, 'max_doctors' => null, 'storage_mb' => 512000, 'ai_enabled' => true, 'whatsapp_enabled' => true]],
        ];

        foreach ($plans as $plan) {
            SaasPlan::query()->firstOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
