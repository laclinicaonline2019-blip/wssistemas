@php
    $cls = ['scheduled' => 'badge-info', 'confirmed' => 'badge-primary', 'arrived' => 'badge-warning', 'in_service' => 'badge-warning', 'completed' => 'badge-success', 'cancelled' => '', 'no_show' => 'badge-danger'][$status] ?? '';
@endphp
<span class="badge {{ $cls }}">{{ \App\Modules\Scheduling\Models\Appointment::STATUSES[$status] ?? $status }}</span>
