@php
    $map = ['low' => 'text-bg-light', 'medium' => 'text-bg-info', 'high' => 'text-bg-warning', 'critical' => 'text-bg-danger'];
    $ar = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'critical' => 'حرجة'];
@endphp
<span class="badge {{ $map[$priority] ?? 'text-bg-secondary' }}">{{ $ar[$priority] ?? ucfirst($priority) }}</span>
