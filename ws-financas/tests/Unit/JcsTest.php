<?php

namespace Tests\Unit;

use App\Core\Audit\Jcs;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** RFC 8785 (JCS) estrita + conversões explícitas antes da gravação. */
class JcsTest extends TestCase
{
    public function test_exemplo_rfc_3_2_2_ordenacao_e_numeros(): void
    {
        $in = json_decode(<<<'JSON'
{"numbers": [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001],
 "string": "\u20ac$\u000F\u000aA'\u0042\u0022\u005c\\\"\/", "literals": [null, true, false]}
JSON, false);
        $expected = <<<'JSON'
{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
JSON;
        $this->assertSame($expected, Jcs::encode($in));
    }

    public function test_exemplo_rfc_3_2_3_ordenacao_utf16(): void
    {
        $in = json_decode('{"€": "Euro Sign", "\r": "Carriage Return", "דּ": "Hebrew Letter Dalet With Dagesh",
            "1": "One", "😀": "Emoji: Grinning Face", "\u0080": "Control", "ö": "Latin Small Letter O With Diaeresis"}', false);
        $out = Jcs::encode($in);
        $order = array_keys(json_decode($out, true));
        $this->assertSame(["\r", '1', "\u{0080}", "\u{00f6}", "\u{20ac}", "\u{1F600}", "\u{fb33}"], array_map('strval', $order));
    }

    public function test_numeros_formato_ecmascript(): void
    {
        $cases = [[0.0, '0'], [-0.0, '0'], [1.0, '1'], [-1.5, '-1.5'], [1e21, '1e+21'], [1e20, '100000000000000000000'], [123e-9, '1.23e-7'],
            [0.000001, '0.000001'], [5e-324, '5e-324'], [1.7976931348623157e308, '1.7976931348623157e+308'], [0.1 + 0.2, '0.30000000000000004'],
            [9007199254740992.0, '9007199254740992'], [295147905179352830000.0, '295147905179352830000'], [-1e-7, '-1e-7']];
        foreach ($cases as [$n, $exp]) {
            $this->assertSame($exp, Jcs::number($n), (string) $n);
        }
    }

    public function test_strings_escapes_minimos_e_unicode_literal(): void
    {
        $this->assertSame('"\u001f\b\f\n\r\t\\\\\"  "', str_replace(["\u{2028}", "\u{2029}"], [' ', ' '], Jcs::encode("\x1f\x08\x0c\n\r\t\\\"\u{2028}\u{2029}")));
        $this->assertSame("\"\x7f\"", Jcs::encode("\x7f"));
        $this->assertSame('"ação ç 😀"', Jcs::encode('ação ç 😀'));
        // sem normalização: "é" composto e decomposto continuam diferentes
        $this->assertNotSame(Jcs::encode("\u{00e9}"), Jcs::encode("e\u{0301}"));
    }

    public function test_recusa_o_que_nao_e_i_json(): void
    {
        foreach (["\xff\xfe", (float) NAN, INF, 9007199254740992, ['a' => 1]] as $bad) {
            try {
                Jcs::encode($bad);
                $this->fail('deveria recusar '.var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_objeto_vazio_e_lista_vazia_sao_distintos(): void
    {
        $this->assertSame('{}', Jcs::encode(new \stdClass));
        $this->assertSame('[]', Jcs::encode([]));
        $this->assertSame('{"a":{},"b":[]}', Jcs::encode(Jcs::prepare(['a' => new \stdClass, 'b' => []])));
    }

    public function test_conversoes_explicitas_sem_perda(): void
    {
        $tree = Jcs::prepare(['bin' => "\xff\x00a", 'big' => PHP_INT_MAX, 'nan' => NAN, 'inf' => -INF, 'zero' => -0.0, "k\xff" => 1, 'num' => [1 => 'x', 0 => 'y']]);
        $this->assertEquals((object) ['$wsf' => 'bytes', 'b64' => base64_encode("\xff\x00a")], $tree->bin);
        $this->assertEquals((object) ['$wsf' => 'int', 'dec' => (string) PHP_INT_MAX], $tree->big);
        $this->assertEquals((object) ['$wsf' => 'num', 'v' => 'NaN'], $tree->nan);
        $this->assertEquals((object) ['$wsf' => 'num', 'v' => '-Infinity'], $tree->inf);
        $this->assertSame('$wsf-bytes:'.base64_encode("k\xff"), array_keys(get_object_vars($tree))[5]);
        $this->assertInstanceOf(\stdClass::class, $tree->num);          // chaves numéricas fora de ordem continuam objeto
        $this->assertSame('{"0":"y","1":"x"}', Jcs::encode($tree->num));
        // ida e volta: a árvore gravada e relida produz a mesma forma canônica
        $stored = Jcs::toStorableJson($tree);
        $this->assertSame(Jcs::encode($tree), Jcs::encode(json_decode($stored, false)));
    }

    public function test_ida_e_volta_aleatoria(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 2000; $i++) {
            $v = ['a' => mt_rand() / mt_getrandmax() * (10 ** mt_rand(-30, 30)), 'b' => [mt_rand(-1000000, 1000000), 'x'.$i], 'ç' => ['z' => true, 'a' => null]];
            $tree = Jcs::prepare($v);
            $this->assertSame(Jcs::encode($tree), Jcs::encode(json_decode(Jcs::toStorableJson($tree), false)));
        }
    }
}
