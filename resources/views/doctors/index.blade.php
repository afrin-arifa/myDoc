@extends('layouts.app')

@section('title', 'Find a doctor | myDoc')

@section('content')
    <div class="container py-5">
        @php($baseQuery = request()->except(['filter', 'sort', 'page']))

        <div class="directory-heading mb-4">
            <p class="hero__eyebrow mb-2">Find care</p>
            <h1 class="display-6 fw-bold mb-2">Find Your Doctor</h1>
            <p class="lead text-secondary mb-0">Connect with a doctor who fits your needs, schedule, and budget.</p>
        </div>

        <form class="directory-search mb-4" method="GET" action="{{ route('doctors.index') }}">
            <label class="visually-hidden" for="search">Search doctors</label>
            <div class="input-group input-group-lg">
                <input class="form-control" id="search" name="search" type="search" value="{{ request('search') }}"
                    placeholder="Try 'cardiologist' or 'Dr. Smith'...">
                @if (request('specialty'))
                    <input name="specialty" type="hidden" value="{{ request('specialty') }}">
                @endif
                @if (request('filter'))
                    <input name="filter" type="hidden" value="{{ request('filter') }}">
                @endif
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <button class="btn btn-outline-primary d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#directory-filters"
                aria-controls="directory-filters" aria-expanded="false">Filter doctors</button>
            <p class="text-secondary mb-0"><strong class="text-body">{{ $doctors->total() }}</strong> doctors available</p>
            @if (request()->hasAny(['search', 'specialty', 'filter', 'sort']))
                <a class="small" href="{{ route('doctors.index') }}">Clear all filters</a>
            @endif
        </div>

        <div class="collapse d-lg-block" id="directory-filters">
            <div class="filter-chips mb-5" aria-label="Doctor filters">
                <a class="filter-chip {{ request('filter', 'all') === 'all' ? 'is-active' : '' }}" href="{{ route('doctors.index', $baseQuery) }}">All</a>
                <a class="filter-chip {{ request('filter') === 'today' ? 'is-active' : '' }}" href="{{ route('doctors.index', array_merge($baseQuery, ['filter' => 'today'])) }}">Available today</a>
                <a class="filter-chip {{ request('filter') === 'fee' ? 'is-active' : '' }}" href="{{ route('doctors.index', array_merge($baseQuery, ['filter' => 'fee'])) }}">Lowest fee</a>
                <a class="filter-chip {{ request('filter') === 'experience' ? 'is-active' : '' }}" href="{{ route('doctors.index', array_merge($baseQuery, ['filter' => 'experience'])) }}">Most experienced</a>
                <div class="filter-chip-select">
                    <label class="visually-hidden" for="specialty">Specialty</label>
                    <select class="form-select form-select-sm" id="specialty" name="specialty" form="specialty-filter-form">
                        <option value="">All specialties</option>
                        @foreach ($specialties as $specialty)
                            <option value="{{ $specialty->id }}" @selected(request('specialty') == $specialty->id)>{{ $specialty->name }}</option>
                        @endforeach
                    </select>
                </div>
                <form id="specialty-filter-form" method="GET" action="{{ route('doctors.index') }}">
                    @if (request('search'))
                        <input name="search" type="hidden" value="{{ request('search') }}">
                    @endif
                    @if (request('filter'))
                        <input name="filter" type="hidden" value="{{ request('filter') }}">
                    @endif
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Apply specialty</button>
                </form>
            </div>
        </div>

        <div class="row g-4">
            @forelse ($doctors as $doctor)
                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <x-doctors.doctor-card :doctor="$doctor" />
                </div>
            @empty
                <div class="col-12">
                    <x-ui.empty-state
                        title="No doctors matched those filters."
                        message="Try a different search or browse all available providers."
                        action-label="Clear filters"
                        :action-url="route('doctors.index')"
                    />
                </div>
            @endforelse
        </div>

        @if ($doctors->hasPages())
            <div class="directory-pagination mt-5">
                {{ $doctors->links() }}
            </div>
        @endif
    </div>
@endsection
