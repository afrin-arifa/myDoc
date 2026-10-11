@extends('layouts.app')

@section('title', 'Patient dashboard | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-3">Welcome, {{ auth()->user()->name }}.</h1>
        <p class="text-secondary">Manage your care journey and appointments.</p>
        <a class="btn btn-primary" href="{{ route('patient.profile') }}">Manage profile</a>
    </div>
@endsection
