@props(['eyebrow', 'title', 'description' => null])

<div class="auth-shell">
    <div class="auth-shell__card">
        <aside class="auth-shell__brand">
            <p class="auth-shell__badge"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i>Your care, in one place</p>
            <p class="auth-shell__title">Care that fits<br>around your life.</p>
            <ul class="auth-shell__list">
                <li><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Find the right doctor by specialty and fee</li>
                <li><i class="fa-regular fa-calendar-check" aria-hidden="true"></i>Request a time that suits you</li>
                <li><i class="fa-regular fa-folder-open" aria-hidden="true"></i>Keep your medical records in one place</li>
            </ul>
        </aside>
        <div class="auth-shell__form">
            <div class="auth-shell__inner">
                @guest
                    <x-layout.alerts class="mb-4" />
                @endguest
                <p class="auth-shell__eyebrow">{{ $eyebrow }}</p>
                <h1 class="auth-shell__heading">{{ $title }}</h1>
                @if ($description)
                    <p class="text-secondary mb-4">{{ $description }}</p>
                @endif
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
