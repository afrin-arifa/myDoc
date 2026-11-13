@props(['title', 'message' => null, 'actionLabel' => null, 'actionUrl' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    @if ($icon)
        <span class="empty-state__icon" aria-hidden="true"><i class="{{ $icon }}"></i></span>
    @endif
    <h2 class="h5">{{ $title }}</h2>
    @if ($message)
        <p class="text-secondary mb-3">{{ $message }}</p>
    @endif
    @if ($actionLabel && $actionUrl)
        <a class="btn btn-outline-primary" href="{{ $actionUrl }}">{{ $actionLabel }}</a>
    @endif
    {{ $slot }}
</div>
