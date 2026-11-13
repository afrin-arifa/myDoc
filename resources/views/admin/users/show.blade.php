@extends('layouts.app')
@section('title', 'Manage user | myDoc')
@section('content')
<div class="container py-5"><a href="{{ route('admin.users.index') }}">&larr; Users</a><h1 class="h2 my-4">Manage {{ $user->name }}</h1>
@if ($doctorStatus)
    <x-ui.alert type="info" class="mb-4">
        <p class="mb-1">This is a doctor account. Status: <x-admin.account-status :status="$doctorStatus" :labels="$statusLabels" /></p>
        <p class="mb-0">Invitations, activation and the professional profile are managed on the
            @if ($user->doctorProfile)
                <a href="{{ route('admin.doctors.show', $user->doctorProfile) }}">doctor page</a>.
            @else
                Doctors page.
            @endif
            Changing the email of a doctor with a pending invitation sends a new invitation to the new address.</p>
    </x-ui.alert>
@endif
<form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('admin.users.update', $user) }}" data-disable-on-submit>@csrf @method('PATCH')
<div class="row g-3"><div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required></div><div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required></div><div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"></div><div class="col-md-6"><label class="form-label" for="role">Role</label>
@if ($user->role === 'doctor')
    <input type="hidden" name="role" value="doctor"><input class="form-control" id="role" value="Doctor" readonly aria-describedby="role_help"><div class="form-text" id="role_help">Doctor accounts keep the doctor role.</div>
@else
    <select class="form-select" id="role" name="role" aria-describedby="role_help">@foreach(['patient','admin'] as $role)<option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ ucfirst($role) }}</option>@endforeach</select><div class="form-text" id="role_help">To add a doctor, use <a href="{{ route('admin.doctors.create') }}">Doctors → Add doctor</a>.</div>
@endif
</div><div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address">{{ old('address', $user->address) }}</textarea></div><div class="col-12 form-check ms-2"><input class="form-check-input" id="deactivated" name="deactivated" value="1" type="checkbox" @checked($user->deactivated_at)><label class="form-check-label" for="deactivated">Deactivate account</label></div></div><button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save changes</button></form></div>
@endsection
