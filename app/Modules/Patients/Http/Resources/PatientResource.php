<?php

namespace App\Modules\Patients\Http\Resources;

use App\Core\Support\Format;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Patient */
class PatientResource extends JsonResource
{
    /** Em listagens o CPF vai mascarado (minimização — LGPD). */
    public bool $summary = false;

    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'record_number' => $this->record_number,
            'name' => $this->name,
            'social_name' => $this->social_name,
            'display_name' => $this->displayName(),
            'cpf' => $this->summary ? Format::cpfMasked($this->cpf) : $this->cpf,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'age' => $this->age(),
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'status' => $this->status,
            'anonymized' => $this->isAnonymized(),
        ];

        if ($this->summary) {
            return $base;
        }

        return $base + [
            'rg' => $this->rg,
            'rg_issuer' => $this->rg_issuer,
            'cns' => $this->cns,
            'sex' => $this->sex,
            'gender_identity' => $this->gender_identity,
            'mother_name' => $this->mother_name,
            'email' => $this->email,
            'address' => $this->only(['zip_code', 'street', 'number', 'complement', 'district', 'city', 'state']),
            'preferred_contact' => $this->preferred_contact,
            'notes' => $this->notes,
            'home_branch_id' => $this->home_branch_id,
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map->only(['id', 'type', 'name', 'relationship', 'cpf', 'phone', 'email'])->values()),
            'insurances' => $this->whenLoaded('insurances', fn () => $this->insurances->map(fn ($i) => $i->only(['id', 'insurer_name', 'plan_name', 'card_number', 'is_primary']) + [
                'valid_until' => $i->valid_until?->format('Y-m-d'),
                'expired' => $i->isExpired(),
            ])->values()),
            'consents' => $this->whenLoaded('consents', fn () => collect($this->currentConsents())->map(fn ($c) => [
                'granted' => $c->granted, 'term_version' => $c->term_version, 'channel' => $c->channel, 'recorded_at' => $c->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function summaries(mixed $resource): AnonymousResourceCollection
    {
        $collection = static::collection($resource);
        $collection->collection->each(fn (self $r) => $r->summary = true);

        return $collection;
    }
}
