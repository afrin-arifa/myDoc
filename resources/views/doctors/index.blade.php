@extends('layouts.app')

@section('title', 'Find a doctor | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Find care</p>
        <h1 class="h2 mb-4">Explore doctors accepting patients.</h1>

        <form class="row g-3 mb-5" method="GET" action="{{ route('doctors.index') }}">
            <div class="col-md-5">
                <label class="form-label" for="specialty">Filter by specialty</label>
                <select class="form-select" id="specialty" name="specialty">
                    <option value="">All specialties</option>
                    @foreach ($specialties as $specialty)
                        <option value="{{ $specialty->id }}" @selected(request('specialty') == $specialty->id)>
                            {{ $specialty->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-auto align-self-end">
                <button class="btn btn-primary" type="submit">Apply filter</button>
            </div>
        </form>

        <div class="row g-4">
            @forelse ($doctors as $doctor)
                <div class="col-md-6 col-lg-4">
                    <article class="feature-card bg-white p-4">
                        <p class="hero__eyebrow mb-2">{{ $doctor->professional_title }}</p>
                        <h2 class="h4">{{ $doctor->user->name }}</h2>
                        <p class="feature-card__description">{{ Str::limit($doctor->bio, 130) }}</p>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            @foreach ($doctor->specialties as $specialty)
                                <span class="badge text-bg-light">{{ $specialty->name }}</span>
                            @endforeach
                        </div>
                        <a class="btn btn-outline-primary" href="{{ route('doctors.show', $doctor) }}">View profile</a>
                    </article>
                </div>
            @empty
                <p class="text-secondary">No doctors matched this filter.</p>
            @endforelse
        </div>

        <div class="mt-5">{{ $doctors->links() }}</div>
    </div>
@endsection
