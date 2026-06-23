@extends('layouts.app')
@section('title', 'Template Types')

@section('content')
<a href="{{ route('templates.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Checklist Builder</a>
<h4 class="fw-bold mt-1 mb-3">Template Types</h4>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3">
            <h6 class="fw-bold">Add a type</h6>
            <form method="POST" action="{{ route('types.store') }}" class="d-flex gap-2">
                @csrf
                <input name="name" class="form-control" placeholder="e.g. Loss Prevention" required>
                <button class="btn btn-primary">Add</button>
            </form>
            <p class="text-muted small mt-2 mb-0">Types appear in the template dropdown. A visit's role is decided by whichever user you assign — not by the type.</p>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Type</th><th>Slug</th><th></th></tr></thead>
                <tbody>
                @foreach($types as $t)
                    <tr>
                        <td class="fw-semibold">{{ $t->name }}</td>
                        <td class="small text-muted">{{ $t->slug }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('types.destroy', $t) }}" onsubmit="return confirm('Delete this type?')">
                                @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
