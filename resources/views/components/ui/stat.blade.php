@props(['label', 'value', 'icon', 'tone' => 'primary'])

<div {{ $attributes->merge(['class' => 'ui-stat']) }}>
    <span class="ui-stat__icon ui-stat__icon--{{ $tone }}" aria-hidden="true"><i class="{{ $icon }}"></i></span>
    <div class="min-w-0">
        <p class="ui-stat__label">{{ $label }}</p>
        <p class="ui-stat__value">{{ $value }}</p>
    </div>
</div>
