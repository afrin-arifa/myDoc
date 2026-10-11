@extends('layouts.app')

@section('title', 'Find care that fits your life | myDoc')

@section('content')
    <div class="container py-4 py-lg-5">
        <section class="hero overflow-hidden px-4 py-5 px-lg-5" aria-labelledby="hero-title">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="hero__eyebrow mb-3">Care that starts with listening</p>
                    <h1 class="hero__title display-4 fw-bold mb-4" id="hero-title">
                        Find trusted care for your next step.
                    </h1>
                    <p class="hero__description lead mb-4">
                        Search real doctor profiles, compare care options, and book a time that works for you.
                    </p>
                    <form class="row g-2 mb-3" method="GET" action="{{ route('doctors.index') }}">
                        <div class="col-sm-8">
                            <label class="visually-hidden" for="homepage-search">Search doctors</label>
                            <input class="form-control form-control-lg" id="homepage-search" name="search"
                                placeholder="Search by name or specialty" type="search">
                        </div>
                        <div class="col-sm-4 d-grid">
                            <button class="btn btn-primary btn-lg" type="submit">Find a doctor</button>
                        </div>
                    </form>
                    <a class="btn btn-link px-0" href="#how-it-works">Learn how booking works &darr;</a>
                </div>
                <div class="col-lg-5" aria-hidden="true">
                    <div class="bg-white rounded-4 shadow-sm p-4 p-lg-5">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <span class="feature-card__icon">+</span>
                            <div>
                                <p class="mb-1 fw-semibold">Your care, your pace</p>
                                <p class="small text-secondary mb-0">A clearer way to explore your options.</p>
                            </div>
                        </div>
                        <div class="border-top pt-4">
                            <p class="small text-uppercase text-secondary fw-semibold mb-2">Start here</p>
                            <p class="h5 mb-0">Explore care that feels right for you.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-5" aria-labelledby="specialties-title">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <p class="hero__eyebrow mb-2">Start with a specialty</p>
                    <h2 class="h3 mb-0" id="specialties-title">Find care that fits your needs.</h2>
                </div>
                <a href="{{ route('doctors.index') }}">View all doctors</a>
            </div>
            <div class="row g-3">
                @forelse ($featuredSpecialties as $specialty)
                    <div class="col-6 col-lg-4">
                        <a class="feature-card d-block bg-white p-3 text-decoration-none" href="{{ route('doctors.index', ['specialty' => $specialty->id]) }}">
                            <span class="fw-semibold">{{ $specialty->name }}</span>
                            <span class="d-block small text-secondary">{{ $specialty->doctor_profiles_count }} available doctors</span>
                        </a>
                    </div>
                @empty
                    <div class="col-12"><p class="text-secondary mb-0">Doctor specialties will appear here as providers join myDoc.</p></div>
                @endforelse
            </div>
        </section>

        <section class="pb-5" aria-labelledby="featured-doctors-title">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <p class="hero__eyebrow mb-2">Featured doctors</p>
                    <h2 class="h3 mb-0" id="featured-doctors-title">Meet providers accepting patients.</h2>
                </div>
                <a href="{{ route('doctors.index') }}">Browse the directory</a>
            </div>
            <div class="row g-4">
                @forelse ($featuredDoctors as $doctor)
                    <div class="col-sm-6 col-lg-3">
                        <x-doctors.doctor-card :doctor="$doctor" :show-booking="false" />
                    </div>
                @empty
                    <div class="col-12">
                        <div class="empty-state">
                            <h3 class="h5">Doctors are joining myDoc soon.</h3>
                            <p class="text-secondary mb-3">Browse the directory to check back for available providers.</p>
                            <a class="btn btn-outline-primary" href="{{ route('doctors.index') }}">Open doctor directory</a>
                        </div>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="py-5" id="how-it-works" aria-labelledby="how-it-works-title">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <p class="hero__eyebrow mb-2">How it works</p>
                    <h2 class="h1 mb-3" id="how-it-works-title">A thoughtful first step toward better care.</h2>
                    <p class="hero__description mb-0">
                        We are building a focused experience to make exploring healthcare options feel more straightforward.
                    </p>
                </div>
            </div>
        </section>

        <section class="pb-5" id="features" aria-labelledby="features-title">
            <h2 class="visually-hidden" id="features-title">myDoc features</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card bg-white p-4">
                        <span class="feature-card__icon mb-4" aria-hidden="true">1</span>
                        <h3 class="h4">Explore your options</h3>
                        <p class="feature-card__description mb-0">Browse care information in one clear, welcoming place.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card bg-white p-4">
                        <span class="feature-card__icon mb-4" aria-hidden="true">2</span>
                        <h3 class="h4">Choose with confidence</h3>
                        <p class="feature-card__description mb-0">Use useful details to understand which option may suit you.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card bg-white p-4">
                        <span class="feature-card__icon mb-4" aria-hidden="true">3</span>
                        <h3 class="h4">Stay in control</h3>
                        <p class="feature-card__description mb-0">Keep your next steps simple, organized, and easy to follow.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
