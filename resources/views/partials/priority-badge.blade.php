@php
    $map = ['low' => 'text-bg-light', 'medium' => 'text-bg-info', 'high' => 'text-bg-warning', 'critical' => 'text-bg-danger'];
@endphp
<span class="badge {{ $map[$priority] ?? 'text-bg-secondary' }}">{{ ucfirst($priority) }}</span>
