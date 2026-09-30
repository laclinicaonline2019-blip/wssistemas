<?php

namespace App\Modules\Queue\Http\Resources;

use App\Modules\Queue\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QueueTicket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'is_priority' => $this->is_priority,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'call_count' => $this->call_count,
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'called_at' => $this->called_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'wait_minutes' => $this->waitMinutes(),
            'appointment_id' => $this->appointment_id,
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? ['id' => $this->patient->id, 'name' => $this->patient->displayName()] : null),
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor ? ['id' => $this->doctor->id, 'name' => $this->doctor->displayName()] : null),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'label' => $this->room->label()] : null),
        ];
    }
}
