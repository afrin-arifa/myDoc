@props(['type' => 'success', 'role' => 'status'])

<div {{ $attributes->merge(['class' => 'alert alert-'.$type]) }} role="{{ $role }}">
    {{ $slot }}
</div>
