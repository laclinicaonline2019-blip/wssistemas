<?php

namespace Tests\Unit;

use App\Core\Support\Money;
use App\Services\Assistant\MessageInterpreter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessageInterpreterTest extends TestCase
{
    public static function messages(): array
    {
        return [
            'exemplo 1 do cliente' => ['Paguei R$ 350 de energia da empresa A.', 'despesa', 35000, '2026-11-12', true, 'Energia', 'A'],
            'exemplo 2 do cliente' => ['Recebi R$ 5.000 de um cliente.', 'receita', 500000, '2026-11-12', false, 'Cliente', null],
            'exemplo 3 do cliente' => ['Cadastre uma conta de R$ 1.200 para pagar dia 20.', 'despesa', 120000, '2026-11-20', false, '', null],
            'formato do painel' => ['luz 230 vence 15', 'despesa', 23000, '2026-11-15', false, 'Luz', null],
            'dia já passou vai p/ próximo mês' => ['internet 120 vence 8', 'despesa', 12000, '2026-12-08', false, 'Internet', null],
            'centavos' => ['ifood 45,90', 'despesa', 4590, '2026-11-12', false, 'Ifood', null],
            'mil' => ['Gastei 5 mil com mercadoria na empresa Loja Centro', 'despesa', 500000, '2026-11-12', true, 'Mercadoria', 'Loja Centro'],
            'data explícita' => ['boleto internet 99,90 vence 05/12', 'despesa', 9990, '2026-12-05', false, 'Boleto internet', null],
        ];
    }

    #[DataProvider('messages')]
    public function test_interpreta_lancamentos(string $msg, string $intent, int $cents, string $date, bool $paid, string $desc, ?string $company): void
    {
        $r = (new MessageInterpreter)->interpret($msg, CarbonImmutable::parse('2026-11-12'));
        $this->assertSame($intent, $r['intent']);
        $this->assertSame($cents, $r['amount_cents']);
        $this->assertSame($date, $r['date']);
        $this->assertSame($paid, $r['paid']);
        $this->assertSame($desc, $r['description']);
        $this->assertSame($company, $r['company_hint']);
    }

    public function test_consultas_e_comandos(): void
    {
        $i = new MessageInterpreter;
        $t = CarbonImmutable::parse('2026-11-12');
        $q = $i->interpret('Quanto tenho para pagar amanhã?', $t);
        $this->assertSame('consulta_pagar', $q['intent']);
        $this->assertSame('2026-11-13', $q['period']['from']);
        $this->assertSame('vencidas', $i->interpret('contas vencidas?', $t)['period']['label']);
        $this->assertSame('resumo', $i->interpret('resumo do mês', $t)['intent']);
        $this->assertSame('pagar', $i->interpret('paguei internet', $t)['intent']);
        $this->assertSame('desfazer', $i->interpret('DESFAZER', $t)['intent']);
        $this->assertSame('sim', $i->interpret('Sim', $t)['intent']);
        $this->assertSame(36000, $i->interpret('corrigir para 360', $t)['amount_cents']);
        $this->assertSame(10, $i->interpret('geladeira 3000 em 10x', $t)['installments']);
        $this->assertTrue($i->interpret('aluguel 1.100 dia 5 fixa', $t)['recurring']);
        $this->assertSame('desconhecido', $i->interpret('bom filme ontem', $t)['intent']);
    }

    public function test_money(): void
    {
        $this->assertSame(120050, Money::parse('1.200,50'));
        $this->assertSame(500000, Money::parse('5 mil'));
        $this->assertSame(110000, Money::parse('1.100'));
        $this->assertSame(4590, Money::parse('R$ 45,90'));
        $this->assertNull(Money::parse('abc'));
        $this->assertSame('R$ 1.234,56', Money::brl(123456));
    }
}
