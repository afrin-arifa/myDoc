@extends('layouts.app')
@section('title', 'Invite assistant | myDoc')
@section('content')
<div class="container py-5">
    <a href="{{ route('admin.assistants.index') }}">&larr; Doctor assistants</a>
    <h1 class="h2 mt-3 mb-1">Invite a Doctor Assistant</h1>
    <p class="text-secondary mb-4">The assistant receives an invitation email to set their own password. The link is valid for 72 hours and works once. No password is set or shown here. Assign doctors once the assistant has activated their account.</p>

    <form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('admin.assistants.store') }}" data-disable-on-submit>
        @csrf
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required maxlength="255">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number (optional)</label>
                <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" placeholder="01712345678">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <button class="btn btn-primary mt-4" type="submit" data-loading-text="Sending…">Create account and send invitation</button>
    </form>
</div>
@endsection
