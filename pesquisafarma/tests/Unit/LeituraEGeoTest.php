<?php

namespace Tests\Unit;

use App\Services\AiAssistant;
use App\Services\ReceiptService;
use App\Support\Geo;
use App\Support\Text;
use PHPUnit\Framework\TestCase;

class LeituraEGeoTest extends TestCase
{
    public function test_separa_itens_do_texto_do_cliente(): void
    {
        $items = (new AiAssistant)->parseText("Oi, boa tarde\n2x Losartana 50mg genérico\n- Dipirona 500mg 3 caixas\nRivotril referência; protetor solar");
        $this->assertCount(4, $items);
        $this->assertSame(['Losartana', '50mg', 2, 'generico'], [$items[0]['description'], $items[0]['presentation'], $items[0]['quantity'], $items[0]['preference']]);
        $this->assertSame(3, $items[1]['quantity']);
        $this->assertSame('referencia', $items[2]['preference']);
        $this->assertSame('protetor solar', $items[3]['description']);
    }

    public function test_lista_na_mesma_linha_da_saudacao(): void
    {
        $items = (new AiAssistant)->parseText('Oi, boa tarde! Preciso de 2x Losartana 50mg genérico, Dipirona 500mg e Vitamina D 2000UI por favor');
        $this->assertSame(['Losartana', 'Dipirona', 'Vitamina D'], array_column($items, 'description'));
        $this->assertSame([2, 1, 1], array_column($items, 'quantity'));
        $this->assertSame('2000UI', $items[2]['presentation']);
        $this->assertSame([], (new AiAssistant)->parseText('Bom dia, tudo bem?'));
    }

    public function test_dinheiro_telefone_e_distancia(): void
    {
        $this->assertSame(1234.56, Text::money('R$ 1.234,56'));
        $this->assertSame(12.5, Text::money('12.50'));
        $this->assertSame('5511999998888', Text::phone('(11) 99999-8888'));
        $this->assertSame('(11) 99999-8888', Text::formatPhone('5511999998888'));
        $km = Geo::distanceKm([-23.5505, -46.6333], [-23.5614, -46.6450]);
        $this->assertGreaterThan(1.4, $km);
        $this->assertLessThan(1.8, $km);
    }

    public function test_compara_item_da_nota_com_item_cotado(): void
    {
        $this->assertGreaterThanOrEqual(0.5, ReceiptService::similarity('LOSARTANA POTASSICA 50MG 30CPR EMS', 'Losartana 50mg'));
        $this->assertLessThan(0.34, ReceiptService::similarity('PROTETOR SOLAR FPS50', 'Losartana 50mg'));
    }
}
