@extends('layouts.app')

@section('title', 'Find care that fits your life | myDoc')

@section('content')
    <div class="container py-4 py-lg-5">
        <section class="hero overflow-hidden px-4 py-5 px-lg-5" aria-labelledby="hero-title">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="hero__eyebrow mb-3">Care that starts with listening</p>
                    <h1 class="hero__title display-4 fw-bold mb-4" id="hero-title">
                        Find the right doctor for your next step.
                    </h1>
                    <p class="hero__description lead mb-4">
                        myDoc is a calm, convenient starting point for finding care that matches your needs and schedule.
                    </p>
                    <div class="d-flex flex-column flex-sm-row gap-3" id="find-doctor">
                        <a class="btn btn-primary btn-lg px-4" href="{{ route('doctors.index') }}">Find a doctor</a>
                        <a class="btn btn-outline-secondary btn-lg px-4" href="#how-it-works">Learn more</a>
                    </div>
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
