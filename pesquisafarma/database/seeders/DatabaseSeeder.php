<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Cria apenas o administrador inicial. Dados fictícios: php artisan db:seed --class=DemoSeeder */
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => env('ADMIN_EMAIL', 'admin@pesquisafarma.local')], [
            'name' => 'Administrador', 'role' => 'admin', 'password' => env('ADMIN_PASSWORD', 'Trocar@123'),
        ]);
    }
}
