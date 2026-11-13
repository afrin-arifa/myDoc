@extends('layouts.app')
@section('title', 'Edit assistant | myDoc')
@section('content')
<div class="container py-5">
    <a href="{{ route('admin.assistants.show', $assistant) }}">&larr; {{ $assistant->name }}</a>
    <h1 class="h2 mt-3 mb-1">Edit assistant details</h1>
    <p class="text-secondary mb-4">Only the name, email and mobile number can be changed here. If the email changes while an invitation is outstanding, a new invitation is sent to the new address.</p>

    <form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('admin.assistants.update', $assistant) }}" data-disable-on-submit>
        @csrf @method('PATCH')
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $assistant->name) }}" required maxlength="255">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $assistant->email) }}" required maxlength="255">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number (optional)</label>
                <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone', $assistant->phone) }}" maxlength="30">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save details</button>
    </form>
</div>
@endsection
