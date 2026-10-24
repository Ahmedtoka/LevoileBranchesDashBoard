{{-- Ticket timeline. Expects $updates: a collection of App\Models\TicketUpdate (with ->user), newest first.
     $relativeEvidenceOnly (observation pages): evidence paths there come from clients, so only a relative storage
     path becomes a link; the legacy ticket pages keep their absolute-URL branch. --}}
@php($relativeEvidenceOnly = $relativeEvidenceOnly ?? false)
<ul class="list-unstyled mb-0">
    @forelse($updates as $u)
        <li class="border-start ps-3 pb-3 position-relative">
            <span class="position-absolute bg-primary rounded-circle" style="width:9px;height:9px;left:-5px;top:4px"></span>
            <div class="small">
                <strong>{{ ucwords(str_replace('_',' ',$u->action)) }}</strong>
                @if($u->to_status) → <em>{{ str_replace('_',' ',$u->to_status) }}</em> @endif
            </div>
            @if($u->note)<div class="small text-muted" dir="auto">{{ $u->note }}</div>@endif
            @if($relativeEvidenceOnly)
                @php($evidenceUrl = \App\Support\ObservationLabels::storageUrl($u->evidence_path))
                @if($evidenceUrl)<a href="{{ $evidenceUrl }}" target="_blank" rel="noopener" class="small">View {{ $u->evidence_kind }} photo</a>@endif
            @elseif($u->evidence_path)<a href="{{ str_starts_with($u->evidence_path,'http') ? $u->evidence_path : asset('storage/'.$u->evidence_path) }}" target="_blank" class="small">View {{ $u->evidence_kind }} photo</a>@endif
            <div class="text-muted" style="font-size:.72rem">{{ optional($u->user)->name }} · {{ $u->created_at->format('d M H:i') }}</div>
        </li>
    @empty
        <li class="text-muted small">No activity yet.</li>
    @endforelse
</ul>
