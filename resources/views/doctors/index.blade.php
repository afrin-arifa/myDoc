@extends('layouts.app')

@section('title', 'Find a doctor | myDoc')

@section('content')
    <div class="container py-5">
        @php($baseQuery = array_merge(request()->except(['filter', 'sort', 'page']), $sort !== 'name' ? ['sort' => $sort] : []))

        <div class="directory-heading mb-4">
            <p class="hero__eyebrow mb-2">Find care</p>
            <h1 class="display-6 fw-bold mb-2">Find Your Doctor</h1>
            <p class="lead text-secondary mb-0">Connect with a doctor who fits your needs, schedule, and budget.</p>
        </div>

        <form class="directory-search mb-4" method="GET" action="{{ route('doctors.index') }}" role="search">
            <label class="visually-hidden" for="search">Search doctors</label>
            <div class="input-group input-group-lg">
                <input class="form-control" id="search" name="search" type="search" value="{{ request('search') }}"
                    placeholder="Try 'cardiologist' or 'Dr. Smith'...">
                @if (request('specialty'))
                    <input name="specialty" type="hidden" value="{{ request('specialty') }}">
                @endif
                @if ($availableToday)
                    <input name="filter" type="hidden" value="today">
                @endif
                @if ($sort !== 'name')
                    <input name="sort" type="hidden" value="{{ $sort }}">
                @endif
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <button class="btn btn-outline-primary d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#directory-filters"
                aria-controls="directory-filters" aria-expanded="false">Filter and sort</button>
            <p class="text-secondary mb-0">
                <strong class="text-body">{{ $doctors->total() }}</strong> {{ Str::plural('doctor', $doctors->total()) }} {{ request()->hasAny(['search', 'specialty']) || $availableToday ? 'found' : 'listed' }}
            </p>
            @if (request()->hasAny(['search', 'specialty', 'filter', 'sort']))
                <a class="small" href="{{ route('doctors.index') }}">Clear all filters</a>
            @endif
        </div>

        <div class="collapse d-lg-block" id="directory-filters">
            <div class="directory-controls mb-5">
                <nav class="filter-chips" aria-label="Availability">
                    <a class="filter-chip {{ $availableToday ? '' : 'is-active' }}" href="{{ route('doctors.index', $baseQuery) }}"
                        @if (! $availableToday) aria-current="page" @endif>All doctors</a>
                    <a class="filter-chip {{ $availableToday ? 'is-active' : '' }}" href="{{ route('doctors.index', array_merge($baseQuery, ['filter' => 'today'])) }}"
                        @if ($availableToday) aria-current="page" @endif>Available today</a>
                </nav>
                <form class="directory-refine" method="GET" action="{{ route('doctors.index') }}" aria-label="Specialty and sort order">
                    @if (request('search'))
                        <input name="search" type="hidden" value="{{ request('search') }}">
                    @endif
                    @if ($availableToday)
                        <input name="filter" type="hidden" value="today">
                    @endif
                    <div class="directory-refine__field">
                        <label class="form-label small mb-1" for="specialty">Specialty</label>
                        <select class="form-select form-select-sm" id="specialty" name="specialty">
                            <option value="">All specialties</option>
                            @foreach ($specialties as $specialty)
                                <option value="{{ $specialty->id }}" @selected(request('specialty') == $specialty->id)>{{ $specialty->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="directory-refine__field">
                        <label class="form-label small mb-1" for="sort">Sort by</label>
                        <select class="form-select form-select-sm" id="sort" name="sort">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
                </form>
            </div>
        </div>

        <div class="row g-4">
            @forelse ($doctors as $doctor)
                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <x-doctors.doctor-card :doctor="$doctor" :can-book="$canBook" />
                </div>
            @empty
                <div class="col-12">
                    <x-ui.empty-state
                        :title="$availableToday ? 'No doctors have open times left today.' : 'No doctors matched those filters.'"
                        :message="$availableToday ? 'Try all doctors to see times on other days.' : 'Try a different search or browse all available providers.'"
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
