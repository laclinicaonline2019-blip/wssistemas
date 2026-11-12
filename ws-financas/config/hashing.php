<?php

return [

    /*
    | Argon2id (recomendado OWASP) quando o PHP do servidor oferece suporte;
    | caso contrário bcrypt — comum em hospedagens compartilhadas. Pode ser
    | forçado com HASH_DRIVER. Hashes antigos são migrados no próximo login.
    */
    'driver' => env('HASH_DRIVER', defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => false,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => false,
    ],

    'rehash_on_login' => true,
];
