@extends('layouts.app')

@section('title', 'My profile | myDoc')

@section('content')
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div><p class="hero__eyebrow mb-2">Patient space</p><h1 class="h2 mb-0">My profile</h1></div>
            <a class="btn btn-outline-primary" href="{{ route('patient.appointments.index') }}">Appointment history</a>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('patient.profile.update') }}">
                    @csrf @method('PUT')
                    <h2 class="h4 mb-4">Personal information</h2>
                    @foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone'] as $field => $label)
                        <div class="mb-3"><label class="form-label" for="{{ $field }}">{{ $label }}</label>
                            <input class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}"
                                type="{{ $field === 'email' ? 'email' : 'text' }}" value="{{ old($field, $user->{$field}) }}" required>
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <div class="mb-3"><label class="form-label" for="address">Address</label>
                        <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address" rows="3">{{ old('address', $user->address) }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary">Save profile</button>
                </form>
            </div>
            <div class="col-lg-5">
                <x-forms.password-form />
                <form class="card border-0 shadow-sm p-4 mt-4" method="POST" action="{{ route('patient.account.deactivate') }}">
                    @csrf @method('DELETE')
                    <h2 class="h5">Deactivate account</h2><p class="small text-secondary">You will be signed out and can contact support to restore access.</p>
                    <button class="btn btn-outline-danger">Deactivate my account</button>
                </form>
            </div>
        </div>
    </div>
@endsection
