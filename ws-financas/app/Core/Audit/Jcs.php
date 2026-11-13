<?php

namespace App\Core\Audit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

/**
 * JSON Canonicalization Scheme — RFC 8785, implementação estrita.
 *
 *  - Objetos: propriedades ordenadas por unidades de código UTF-16 (comparação binária de UTF-16BE), recursivamente.
 *  - Listas: ordem preservada. Objeto (stdClass) e lista (array PHP de índices 0..n-1) são distintos, inclusive {} e [].
 *  - Strings: UTF-8 válido obrigatório; escapes somente \" \\ \b \f \n \r \t e \u00xx (minúsculo) para < 0x20;
 *    todo o resto literal (inclusive U+2028/U+2029 e DEL); sem normalização Unicode.
 *  - Números: serialização ECMAScript Number.prototype.toString (dígitos mínimos de ida e volta); -0 → 0.
 *  - Recusa (exceção) o que não é I-JSON: UTF-8 inválido, inteiro fora de ±(2^53−1), NaN/Infinito.
 *
 * Valores fora do I-JSON nunca são "consertados" aqui. Antes de gravar, prepare() os converte de forma
 * EXPLÍCITA e sem perda ({"$wsf": …}); o valor convertido é o que se grava e o que se assina.
 */
final class Jcs
{
    public const MAX_SAFE_INT = 9007199254740991;

    public static function encode(mixed $value): string
    {
        $old = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return self::enc($value);
        } finally {
            ini_set('serialize_precision', (string) $old);
        }
    }

    /**
     * Converte um valor PHP (arrays, escalares, objetos serializáveis) na árvore JSON exata que será gravada e,
     * depois, lida do banco: listas → array PHP; objetos → stdClass. Valores fora do I-JSON viram marcadores explícitos.
     */
    public static function prepare(mixed $value): mixed
    {
        $explicit = self::explicit($value);
        $old = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode($explicit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', (string) $old);
        }

        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Árvore (stdClass/array) → array associativo PHP para gravação pelo cast "array" do Eloquent, preservando {} e []. */
    public static function toStorableJson(mixed $tree): string
    {
        return json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ serialização

    private static function enc(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_int($v)) {
            if ($v > self::MAX_SAFE_INT || $v < -self::MAX_SAFE_INT) {
                throw new InvalidArgumentException('Inteiro fora do intervalo I-JSON (±2^53−1).');
            }

            return (string) $v;
        }
        if (is_float($v)) {
            return self::number($v);
        }
        if (is_string($v)) {
            return self::string($v);
        }
        if (is_array($v)) {
            if (! array_is_list($v)) {
                throw new InvalidArgumentException('Array associativo recebido: use prepare() (objetos devem ser stdClass).');
            }

            return '['.implode(',', array_map(self::enc(...), $v)).']';
        }
        if ($v instanceof stdClass) {
            $props = get_object_vars($v);
            $keys = array_map('strval', array_keys($props));
            $byKey = [];
            foreach ($props as $k => $val) {
                $byKey[(string) $k] = $val;
            }
            foreach ($keys as $k) {
                self::assertUtf8($k);
            }
            usort($keys, fn (string $a, string $b) => strcmp(self::utf16($a), self::utf16($b)));
            $parts = [];
            foreach ($keys as $k) {
                $parts[] = self::string($k).':'.self::enc($byKey[$k]);
            }

            return '{'.implode(',', $parts).'}';
        }
        throw new InvalidArgumentException('Tipo não suportado na serialização canônica: '.get_debug_type($v));
    }

    private static function string(string $s): string
    {
        self::assertUtf8($s);
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $o = ord($c);
            $out .= match (true) {
                $c === '"' => '\\"',
                $c === '\\' => '\\\\',
                $o === 0x08 => '\\b',
                $o === 0x0C => '\\f',
                $o === 0x0A => '\\n',
                $o === 0x0D => '\\r',
                $o === 0x09 => '\\t',
                $o < 0x20 => sprintf('\\u%04x', $o),
                default => $c,
            };
        }

        return $out.'"';
    }

    /** ECMAScript Number::toString com os dígitos mínimos de ida e volta do PHP (serialize_precision = -1). */
    public static function number(float $n): string
    {
        if (is_nan($n) || is_infinite($n)) {
            throw new InvalidArgumentException('NaN/Infinito não são permitidos (I-JSON).');
        }
        if ($n == 0.0) {
            return '0';
        }
        $old = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $repr = json_encode($n);
        } finally {
            ini_set('serialize_precision', (string) $old);
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $repr, $q)) {
            throw new InvalidArgumentException('Número não reconhecido: '.$repr);
        }
        $neg = $q[1];
        $int = $q[2];
        $frac = $q[3] ?? '';
        $exp10 = (int) ($q[4] ?? 0);
        $all = $int.$frac;
        $lead = strlen($all) - strlen(ltrim($all, '0'));
        $digits = rtrim(ltrim($all, '0'), '0');
        if ($digits === '') {
            return '0';
        }
        $k = strlen($digits);
        $e = strlen($int) + $exp10 - $lead;   // valor = 0.digits × 10^e  (ECMAScript: n = e)

        if ($k <= $e && $e <= 21) {
            return $neg.$digits.str_repeat('0', $e - $k);
        }
        if ($e > 0 && $e <= 21) {
            return $neg.substr($digits, 0, $e).'.'.substr($digits, $e);
        }
        if ($e > -6 && $e <= 0) {
            return $neg.'0.'.str_repeat('0', -$e).$digits;
        }
        $x = $e - 1;

        return $neg.$digits[0].($k > 1 ? '.'.substr($digits, 1) : '').'e'.($x >= 0 ? '+' : '-').abs($x);
    }

    // ------------------------------------------------------------------ conversões explícitas (antes de gravar)

    private static function explicit(mixed $v): mixed
    {
        if (is_string($v)) {
            return mb_check_encoding($v, 'UTF-8') ? $v : ['$wsf' => 'bytes', 'b64' => base64_encode($v)];
        }
        if (is_int($v)) {
            return ($v > self::MAX_SAFE_INT || $v < -self::MAX_SAFE_INT) ? ['$wsf' => 'int', 'dec' => (string) $v] : $v;
        }
        if (is_float($v)) {
            return is_nan($v) ? ['$wsf' => 'num', 'v' => 'NaN'] : (is_infinite($v) ? ['$wsf' => 'num', 'v' => $v > 0 ? 'Infinity' : '-Infinity'] : ($v == 0.0 ? 0.0 : $v));
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $key = is_string($k) && ! mb_check_encoding($k, 'UTF-8') ? '$wsf-bytes:'.base64_encode($k) : $k;
                $out[$key] = self::explicit($x);
            }

            return $out;
        }
        if ($v instanceof stdClass) {
            $o = new stdClass;
            foreach (get_object_vars($v) as $k => $x) {
                $o->{$k} = self::explicit($x);
            }

            return $o;
        }
        if ($v instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($v)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }
        if ($v instanceof \JsonSerializable) {
            return self::explicit($v->jsonSerialize());
        }
        if (is_object($v)) {
            return self::explicit(method_exists($v, 'toArray') ? $v->toArray() : (string) json_encode($v));
        }

        return $v;
    }

    private static function assertUtf8(string $s): void
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            throw new InvalidArgumentException('UTF-8 inválido (não é I-JSON).');
        }
    }

    private static function utf16(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
    }
}
