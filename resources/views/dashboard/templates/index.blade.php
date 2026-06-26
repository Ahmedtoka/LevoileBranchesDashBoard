@extends('layouts.app')
@section('title', 'Checklist Builder')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0">Checklist Builder</h4>
        <p class="text-muted small mb-0">Create checklist templates, choose a type, and define sections & questions.</p>
    </div>
    <div>
        <a href="{{ route('types.index') }}" class="btn btn-outline-secondary"><i class="bi bi-tags"></i> Manage types</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTemplateModal"><i class="bi bi-plus-lg"></i> New template</button>
    </div>
</div>

<div class="row g-3 mb-3">
    @php $boxes = [['Templates',$summary['templates'],'primary','ui-checks'],['Types',$summary['types'],'info','tags'],['Sections',$summary['sections'],'secondary','collection'],['Questions',$summary['questions'],'dark','question-circle']]; @endphp
    @foreach($boxes as [$l,$v,$c,$icon])
        <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="text-muted small"><i class="bi bi-{{ $icon }} text-{{ $c }} me-1"></i>{{ $l }}</div><div class="value">{{ $v }}</div></div></div>
    @endforeach
</div>

<form class="mb-3" method="GET">
    <div class="input-group input-group-sm" style="max-width:320px">
        <input name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ t('tpl.search','بحث في القوالب…') }}">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
</form>

<div class="row g-3">
    @forelse($templates as $t)
        <div class="col-md-6 col-lg-4">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <h6 class="fw-bold mb-0">{{ $t->name }}</h6>
                    <span class="badge text-bg-light">{{ ucwords(str_replace('_',' ',$t->type)) }}</span>
                </div>
                <div class="small text-muted mb-2">{{ Str::limit($t->description, 70) }}</div>
                <div class="d-flex gap-3 mb-2">
                    <div><div class="fw-bold">{{ $t->sections_count }}</div><div class="text-muted" style="font-size:.72rem">Sections</div></div>
                    <div><div class="fw-bold">{{ $t->questions_count }}</div><div class="text-muted" style="font-size:.72rem">Questions</div></div>
                    <div>
                        @if($t->scored)<span class="badge text-bg-info">Scored</span>@endif
                        {!! $t->active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' !!}
                    </div>
                </div>
                <div class="mt-auto d-flex gap-2">
                    <a href="{{ route('templates.edit', $t) }}" class="btn btn-sm btn-outline-primary flex-fill">Build</a>
                    <form method="POST" action="{{ route('templates.destroy', $t) }}" onsubmit="return confirm('Delete this template and all its questions?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12"><div class="card p-4 text-center text-muted">No templates yet. Create your first one.</div></div>
    @endforelse
</div>

<!-- New Template Modal -->
<div class="modal fade" id="newTemplateModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('templates.store') }}">
            @csrf
            <div class="modal-header"><h6 class="modal-title">{{ t('tpl.new','قالب شيك ليست جديد') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Template name</label>
                    <input name="name" class="form-control" required placeholder="e.g. Operation Manager">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select" required>
                        @foreach($types as $type)
                            <option value="{{ $type->slug }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Need another type? Use “Manage types”.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="scored" value="1" class="form-check-input" id="scored">
                    <label class="form-check-label small" for="scored">Scored template (weighted sections & per-question score)</label>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Create & build</button></div>
        </form>
    </div>
</div>
@endsection
