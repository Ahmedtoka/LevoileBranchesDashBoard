@php($status = (string) ($status ?? ''))
<span class="badge rounded-pill text-bg-{{ \App\Support\ObservationLabels::statusColor($status) }}">{{ \App\Support\ObservationLabels::status($status) }}</span>
