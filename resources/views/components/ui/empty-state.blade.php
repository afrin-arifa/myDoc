@props(['title', 'message' => null, 'actionLabel' => null, 'actionUrl' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <h2 class="h5">{{ $title }}</h2>
    @if ($message)
        <p class="text-secondary mb-3">{{ $message }}</p>
    @endif
    @if ($actionLabel && $actionUrl)
        <a class="btn btn-outline-primary" href="{{ $actionUrl }}">{{ $actionLabel }}</a>
    @endif
    {{ $slot }}
</div>
