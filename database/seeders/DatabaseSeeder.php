<?php

namespace Database\Seeders;

use App\Core\Access\PermissionRegistry;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Dados essenciais (todos os ambientes). Dados fictícios: DemoSeeder. */
    public function run(PermissionRegistry $registry): void
    {
        $registry->sync();
        $this->call(PlanSeeder::class);
        $this->call(ClinicalCatalogSeeder::class);
    }
}
