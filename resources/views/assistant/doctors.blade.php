@extends('layouts.app')

@section('title', 'My doctors | myDoc')

@section('content')
    <div class="container py-5">
        <a href="{{ route('assistant.dashboard') }}">&larr; Dashboard</a>
        <h1 class="h2 mt-3 mb-1">My doctors</h1>
        <p class="text-secondary mb-4">Doctors you are currently assigned to. Choose one to open their workspace.</p>

        @if ($doctors->isEmpty())
            <x-ui.empty-state class="text-center py-5" icon="fa-solid fa-user-doctor" title="No doctors assigned yet"
                message="An administrator assigns you to the doctors you support." />
        @else
            @include('assistant.partials.doctor-cards', ['doctors' => $doctors])
        @endif
    </div>
@endsection
