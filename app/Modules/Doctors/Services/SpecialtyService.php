<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Doctors\Models\Specialty;

class SpecialtyService
{
    public function create(array $data): Specialty
    {
        return Specialty::create($data);
    }

    public function update(Specialty $specialty, array $data): Specialty
    {
        $specialty->update($data);

        return $specialty;
    }

    /** Cria as especialidades padrão ausentes na empresa atual. */
    public function createDefaults(): int
    {
        $created = 0;

        foreach (config('specialties') as $item) {
            $specialty = Specialty::query()->firstOrCreate(['name' => $item['name']], $item);
            $created += (int) $specialty->wasRecentlyCreated;
        }

        return $created;
    }
}
