<?php

namespace Tests\Unit;

use App\Core\Validation\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_validates_cnpj(string $value, bool $expected): void
    {
        $this->assertSame($expected, Cnpj::isValid($value));
    }

    public static function cases(): array
    {
        return [
            'válido com máscara' => ['11.222.333/0001-81', true],
            'válido sem máscara' => ['11222333000181', true],
            'dígito errado' => ['11222333000182', false],
            'repetido' => ['11111111111111', false],
            'curto' => ['1122233300018', false],
            'vazio' => ['', false],
        ];
    }
}
