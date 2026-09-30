<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = $this->relationLoaded('branch') ? $this->branch->timezone : 'America/Sao_Paulo';

        return [
            'id' => $this->id,
            'protocol' => $this->protocol,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'local_date' => $this->starts_at->setTimezone($tz)->format('Y-m-d'),
            'local_time' => $this->starts_at->setTimezone($tz)->format('H:i'),
            'is_overbook' => $this->is_overbook,
            'channel' => $this->channel,
            'payer_type' => $this->payer_type,
            'price_cents' => $this->price_cents,
            'notes' => $this->notes,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only(['id', 'name'])),
            'doctor' => $this->whenLoaded('doctor', fn () => ['id' => $this->doctor->id, 'name' => $this->doctor->displayName(), 'registration' => $this->doctor->registration()]),
            'patient' => $this->whenLoaded('patient', fn () => ['id' => $this->patient->id, 'name' => $this->patient->displayName(), 'record_number' => $this->patient->record_number]),
            'service' => $this->whenLoaded('service', fn () => $this->service?->only(['id', 'name', 'price_cents'])),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'label' => $this->room->label()] : null),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
