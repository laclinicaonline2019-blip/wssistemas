<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Neighborhood;
use App\Models\Pharmacy;
use App\Models\PrizeCampaign;
use App\Models\Promotion;
use App\Models\Reward;
use App\Models\User;
use App\Support\Text;
use Illuminate\Database\Seeder;

/** Dados FICTÍCIOS para demonstração (farmácias, clientes e usuários inventados). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $sp = City::findOrCreateByName('São Paulo', 'SP');
        $sp->update(['lat' => -23.5505, 'lng' => -46.6333]);
        $hoods = [];
        foreach ([
            ['Centro', -23.5505, -46.6333], ['Bela Vista', -23.5614, -46.6450], ['Liberdade', -23.5587, -46.6350], ['Consolação', -23.5536, -46.6570],
            ['Vila Mariana', -23.5893, -46.6345], ['Moema', -23.6008, -46.6634], ['Pinheiros', -23.5672, -46.7018], ['Mooca', -23.5604, -46.5996],
            ['Tatuapé', -23.5400, -46.5763], ['Santana', -23.5025, -46.6253], ['Ipiranga', -23.5893, -46.6094], ['Butantã', -23.5719, -46.7086],
        ] as [$name, $lat, $lng]) {
            $hoods[$name] = Neighborhood::query()->firstOrCreate(['slug' => Text::slug($name), 'city_id' => $sp->id], ['name' => $name, 'lat' => $lat, 'lng' => $lng]);
        }

        // Outros municípios e estados (fictício)
        $others = [
            ['Campinas', 'SP', -22.9099, -47.0626, [['Cambuí', -22.8935, -47.0505], ['Centro', -22.9056, -47.0608], ['Taquaral', -22.8738, -47.0532]],
                [['Farmácia Cambuí Vida', 'Cambuí', -22.8940, -47.0510, 'mensal', 5.0], ['Drogaria Central Campinas', 'Centro', -22.9050, -47.0600, 'semanal', 6.0]]],
            ['Rio de Janeiro', 'RJ', -22.9068, -43.1729, [['Copacabana', -22.9711, -43.1822], ['Tijuca', -22.9249, -43.2311]],
                [['Farmácia Copa Saúde', 'Copacabana', -22.9700, -43.1830, 'mensal', 5.0]]],
        ];
        foreach ($others as [$cityName, $uf, $clat, $clng, $hoodList, $pharmList]) {
            $city = City::findOrCreateByName($cityName, $uf);
            $city->update(['lat' => $clat, 'lng' => $clng]);
            $local = [];
            foreach ($hoodList as [$hn, $hlat, $hlng]) {
                $local[$hn] = Neighborhood::query()->firstOrCreate(['slug' => Text::slug($hn), 'city_id' => $city->id], ['name' => $hn, 'lat' => $hlat, 'lng' => $hlng]);
            }
            foreach ($pharmList as $j => [$pn, $ph, $plat, $plng, $cycle, $pct]) {
                Pharmacy::query()->firstOrCreate(['trade_name' => $pn], ['legal_name' => $pn.' Ltda (fictícia)', 'cnpj' => sprintf('22%06d0001%02d', crc32($pn) % 999999, 30 + $j),
                    'whatsapp' => '55'.($uf === 'RJ' ? '21' : '19').'99000'.str_pad((string) (crc32($pn) % 10000), 4, '0', STR_PAD_LEFT), 'address' => 'Rua Exemplo '.($j + 1), 'number' => '50',
                    'neighborhood_id' => $local[$ph]->id, 'lat' => $plat, 'lng' => $plng, 'commission_percent' => $pct, 'commission_cycle' => $cycle,
                    'delivers' => true, 'delivery_radius_km' => 5, 'delivery_base_fee' => 6, 'delivery_fee_per_km' => 1, 'delivery_eta_min' => 45, 'payment_methods' => 'PIX, cartão']);
            }
        }

        $pharmacies = [
            ['Farmácia Saúde Já', 'Centro', -23.5480, -46.6360, 4, 'mensal', 5.0],
            ['Drogaria Bem Estar', 'Bela Vista', -23.5620, -46.6440, 5, 'semanal', 6.0],
            ['Farma Popular Liberdade', 'Liberdade', -23.5590, -46.6340, 3.5, 'mensal', 4.0],
            ['Drogaria Vida Mariana', 'Vila Mariana', -23.5880, -46.6350, 6, 'mensal', 5.0],
            ['Farmácia Moema 24h', 'Moema', -23.6000, -46.6620, 5, 'semanal', 7.0],
            ['Drogaria Pinheiros Center', 'Pinheiros', -23.5660, -46.7000, 4, 'mensal', 5.5],
            ['Farmácia da Mooca', 'Mooca', -23.5610, -46.6000, 5, 'mensal', 4.5],
            ['Drogaria Tatuapé Express', 'Tatuapé', -23.5410, -46.5770, 6, 'semanal', 5.0],
        ];
        foreach ($pharmacies as $i => [$name, $hood, $lat, $lng, $radius, $cycle, $pct]) {
            $p = Pharmacy::query()->firstOrCreate(['trade_name' => $name], [
                'legal_name' => $name.' Ltda (fictícia)', 'cnpj' => sprintf('11%06d0001%02d', 100 + $i, 10 + $i), 'phone' => '1130000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'whatsapp' => '5511990000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'address' => 'Rua Exemplo '.($i + 1), 'number' => (string) (100 + $i * 10),
                'neighborhood_id' => $hoods[$hood]->id, 'lat' => $lat, 'lng' => $lng, 'commission_percent' => $pct, 'commission_cycle' => $cycle,
                'delivers' => $i !== 2, 'delivery_radius_km' => $radius, 'delivery_base_fee' => 5, 'delivery_fee_per_km' => 1.5, 'free_delivery_above' => 120, 'delivery_eta_min' => 40 + $i * 5,
                'payment_methods' => 'PIX, cartão, dinheiro', 'opening_hours' => 'Seg a sáb 8h–22h',
            ]);
            if ($i === 0) {
                User::query()->firstOrCreate(['email' => 'farmacia@demo.local'], ['name' => 'Gerente Saúde Já', 'role' => 'farmacia', 'pharmacy_id' => $p->id, 'password' => 'Demo@12345']);
            }
        }

        User::query()->firstOrCreate(['email' => 'televendas1@demo.local'], ['name' => 'Ana Televendas', 'role' => 'televendas', 'password' => 'Demo@12345']);
        User::query()->firstOrCreate(['email' => 'televendas2@demo.local'], ['name' => 'Bruno Televendas', 'role' => 'televendas', 'password' => 'Demo@12345']);

        foreach ([['Maria Fictícia Souza', 'Bela Vista'], ['João Exemplo Lima', 'Liberdade'], ['Carla Demonstração', 'Moema'], ['Pedro Teste Alves', 'Mooca']] as $i => [$name, $hood]) {
            Customer::query()->firstOrCreate(['phone' => '551198888000'.$i], ['name' => $name, 'neighborhood_id' => $hoods[$hood]->id,
                'address' => 'Avenida Demonstração', 'number' => (string) (10 + $i), 'accepts_marketing' => true, 'lgpd_consent_at' => now(), 'origin' => 'painel']);
        }

        LoyaltyRule::query()->firstOrCreate(['name' => '1 ponto por real'], ['type' => 'por_valor', 'points_per_real' => 1]);
        LoyaltyRule::query()->firstOrCreate(['name' => 'Vitaminas em dobro'], ['type' => 'por_produto', 'keyword' => 'vitamina', 'bonus_points' => 50]);
        Reward::query()->firstOrCreate(['name' => 'Squeeze Pesquisa Farma'], ['points_cost' => 150, 'stock' => 50, 'description' => 'Garrafinha 500 ml']);
        Reward::query()->firstOrCreate(['name' => 'Kit higiene'], ['points_cost' => 400, 'stock' => 20, 'min_purchases' => 2]);
        Reward::query()->firstOrCreate(['name' => 'Vale-desconto R$ 20'], ['points_cost' => 600, 'description' => 'Em qualquer farmácia parceira']);
        PrizeCampaign::query()->firstOrCreate(['month' => now()->format('Y-m')], ['title' => 'Cesta de cuidados do mês', 'rule' => 'sorteio']);
        Promotion::query()->firstOrCreate(['title' => 'Protetor solar FPS 50 com desconto'], ['pharmacy_id' => Pharmacy::first()->id, 'product_name' => 'Protetor solar FPS 50 200 ml',
            'original_price' => 79.9, 'price' => 54.9, 'valid_from' => today(), 'valid_until' => today()->addDays(3), 'status' => 'pendente']);
    }
}
