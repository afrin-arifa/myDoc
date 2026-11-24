{{-- Flash messages and validation errors. Shown by the layout for signed-in users, and inside the card on sign-in pages. --}}
@if (session('status') || session('warning') || $errors->any())
    <div {{ $attributes->merge(['class' => 'app-alerts']) }}>
        @if (session('status'))
            <x-ui.alert class="mb-0">{{ session('status') }}</x-ui.alert>
        @endif

        @if (session('warning'))
            <x-ui.alert type="warning" role="alert" class="mb-0">{{ session('warning') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert type="danger" role="alert" class="mb-0">
                <p class="fw-semibold mb-2">Please review the highlighted fields.</p>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif
    </div>
@endif
