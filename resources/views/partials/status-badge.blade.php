@php
    $map = [
        'open' => 'secondary', 'assigned' => 'info', 'on_the_way' => 'primary',
        'in_progress' => 'warning', 'waiting_approval' => 'purple', 'rejected' => 'danger',
        'postponed' => 'warning', 'not_fixed' => 'danger',
        'closed' => 'success', 'completed' => 'success', 'checked_in' => 'info', 'cancelled' => 'dark',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
@endphp
@if($color === 'purple')
    <span class="badge badge-status" style="background:#7c3aed">{{ $label }}</span>
@else
    <span class="badge text-bg-{{ $color }} badge-status">{{ $label }}</span>
@endif
