@extends('layouts.app')

@section('title', 'Invitation unavailable | myDoc')

@section('content')
    <x-auth.card eyebrow="Doctor Assistant invitation" title="Invitation unavailable">
        <x-ui.alert type="warning" role="alert" class="mb-4">{{ $message }}</x-ui.alert>
        <a class="btn btn-outline-primary w-100" href="{{ route('login') }}">Go to log in</a>
    </x-auth.card>
@endsection
