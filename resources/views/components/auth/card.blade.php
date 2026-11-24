@props(['eyebrow', 'title', 'description' => null])

<div class="auth-shell">
    <aside class="auth-shell__brand">
        <div>
            <p class="auth-shell__title">Care that fits<br>around your life.</p>
            <ul class="auth-shell__list">
                <li><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Find the right doctor by specialty and fee</li>
                <li><i class="fa-regular fa-calendar-check" aria-hidden="true"></i>Request a time that suits you</li>
                <li><i class="fa-regular fa-folder-open" aria-hidden="true"></i>Keep your medical records in one place</li>
            </ul>
        </div>
    </aside>
    <div class="auth-shell__form">
        <div class="auth-shell__inner">
            <p class="hero__eyebrow mb-2">{{ $eyebrow }}</p>
            <h1 class="h2 mb-2">{{ $title }}</h1>
            @if ($description)
                <p class="text-secondary mb-4">{{ $description }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
