@php
    $map = ['trial' => ['Trial', 'badge-info'], 'active' => ['Ativa', 'badge-success'], 'suspended' => ['Suspensa', 'badge-warning'], 'cancelled' => ['Cancelada', 'badge-danger']];
    [$label, $cls] = $map[$status] ?? [$status, ''];
@endphp
<span class="badge {{ $cls }}">{{ $label }}</span>
