<?php

use App\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Vários municípios e estados: cidades (com UF) e bairros/farmácias/clientes ligados a uma cidade. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->index();
            $t->string('uf', 2)->index();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['slug', 'uf']);
        });
        foreach (['neighborhoods', 'pharmacies', 'customers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('city_id')->nullable()->constrained()->nullOnDelete());
        }

        // Migra as cidades que já existem como texto nos bairros
        foreach (DB::table('neighborhoods')->select('city', 'uf')->where('city', '!=', '')->distinct()->get() as $row) {
            $slug = Text::slug($row->city);
            $id = DB::table('cities')->where('slug', $slug)->where('uf', (string) $row->uf)->value('id')
                ?? DB::table('cities')->insertGetId(['name' => $row->city, 'slug' => $slug, 'uf' => (string) $row->uf, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('neighborhoods')->where('city', $row->city)->where('uf', $row->uf)->update(['city_id' => $id]);
        }
        foreach (['pharmacies', 'customers'] as $table) {
            foreach (DB::table($table)->whereNotNull('neighborhood_id')->get(['id', 'neighborhood_id']) as $r) {
                DB::table($table)->where('id', $r->id)->update(['city_id' => DB::table('neighborhoods')->where('id', $r->neighborhood_id)->value('city_id')]);
            }
        }
    }

    public function down(): void
    {
        foreach (['neighborhoods', 'pharmacies', 'customers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('city_id'));
        }
        Schema::dropIfExists('cities');
    }
};
