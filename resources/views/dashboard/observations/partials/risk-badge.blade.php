@if(!empty($risk))
    <span class="badge" style="background: {{ $risk->color }}; color: #fff">{{ dt($risk->name_ar, $risk->name_en) }}</span>
@else
    <span class="text-muted">—</span>
@endif
