@extends('layouts.app')
@section('title', 'Doctors | myDoc')
@section('content')
<div class="container py-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Doctors</h1>
            <p class="text-secondary mb-0">Create doctor accounts, send invitations and manage account status.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.doctors.create') }}"><i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>Add doctor</a>
    </div>

    <form class="filter-bar row g-3 align-items-end mb-4" method="GET" action="{{ route('admin.doctors.index') }}">
        <div class="col-md-6">
            <label class="form-label" for="search">Search</label>
            <input class="form-control" id="search" name="search" value="{{ request('search') }}" placeholder="Name, email, title or license" maxlength="100">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="status">Account status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button class="btn btn-outline-primary" type="submit">Filter</button>
        </div>
    </form>

    @if ($doctors->isEmpty())
        <x-ui.empty-state title="No doctors found"
            :message="request()->hasAny(['search', 'status']) ? 'Try a different search or status filter.' : 'Add the first doctor to start onboarding.'"
            :action-label="request()->hasAny(['search', 'status']) ? null : 'Add doctor'"
            :action-url="request()->hasAny(['search', 'status']) ? null : route('admin.doctors.create')" />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr><th scope="col">Doctor</th><th scope="col">Title &amp; license</th><th scope="col">Specialties</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($doctors as $doctor)
                        <tr>
                            <td><strong>{{ $doctor->user->name }}</strong><br><span class="small text-secondary">{{ $doctor->user->email }}</span></td>
                            <td>{{ $doctor->professional_title }}<br><span class="small text-secondary">License {{ $doctor->license_number }}</span></td>
                            <td>
                                @forelse ($doctor->specialties as $specialty)
                                    <span class="badge text-bg-light me-1">{{ $specialty->name }}</span>
                                @empty
                                    <span class="small text-secondary">None</span>
                                @endforelse
                            </td>
                            <td><x-admin.account-status :status="$statuses[$doctor->id]" :labels="$statusLabels" /></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.doctors.show', $doctor) }}">Manage</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $doctors->links() }}
    @endif
</div>
@endsection
