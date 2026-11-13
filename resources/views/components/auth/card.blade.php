@props(['eyebrow', 'title', 'description' => null])

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-lg-5">
                    <p class="hero__eyebrow mb-2">{{ $eyebrow }}</p>
                    <h1 class="h2 mb-2">{{ $title }}</h1>
                    @if ($description)
                        <p class="text-secondary mb-4">{{ $description }}</p>
                    @endif
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
