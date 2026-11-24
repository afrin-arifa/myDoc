@extends('layouts.app')
@section('title', 'Manage doctor | myDoc')
@section('content')
<div class="container py-5">
    <a href="{{ route('admin.doctors.index') }}">&larr; Doctors</a>
    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 my-4">
        <x-ui.avatar :name="$doctor->user->name" :src="$doctor->photoUrl() ?? asset('images/doctor.png')" size="xl" />
        <div>
            <h1 class="h2 mb-1">{{ $doctor->user->name }}</h1>
            <x-admin.account-status :status="$status" :labels="$statusLabels" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="card border-0 shadow-sm p-4 mb-4" aria-labelledby="account-heading">
                <h2 class="h5" id="account-heading">Account</h2>
                <dl class="mb-3">
                    <dt class="small text-secondary">Status</dt>
                    <dd><x-admin.account-status :status="$status" :labels="$statusLabels" /></dd>
                    @if ($invitationExpiresAt)
                        <dt class="small text-secondary">Invitation {{ $status === 'invitation_expired' ? 'expired' : 'expires' }}</dt>
                        <dd>{{ $invitationExpiresAt->format('M j, Y g:i A') }} ({{ config('app.timezone') }})</dd>
                    @endif
                    <dt class="small text-secondary">Appointments on record</dt>
                    <dd>{{ $appointmentCount }}</dd>
                </dl>

                @if (in_array($status, ['invitation_pending', 'invitation_expired'], true))
                    <p class="small text-secondary">The doctor activates the account by accepting the invitation. Sending a new invitation invalidates earlier links.</p>
                    <form method="POST" action="{{ route('admin.doctors.invitation', $doctor) }}" class="mb-2" data-disable-on-submit>
                        @csrf
                        <button class="btn btn-primary w-100" type="submit" data-loading-text="Sending…">Resend invitation</button>
                    </form>
                @endif

                @if ($status === 'active')
                    <form method="POST" action="{{ route('admin.doctors.status', $doctor) }}"
                        data-confirm="Deactivate {{ $doctor->user->name }}? They will be signed out of the doctor workspace and hidden from patients. Appointments are kept.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-outline-danger w-100" type="submit">Deactivate account</button>
                    </form>
                @elseif ($status === 'deactivated')
                    <form method="POST" action="{{ route('admin.doctors.status', $doctor) }}"
                        data-confirm="Reactivate {{ $doctor->user->name }}? They will be able to sign in and appear to patients again.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="activate">
                        <button class="btn btn-success w-100" type="submit">Activate account</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.doctors.status', $doctor) }}"
                        data-confirm="Cancel the invitation for {{ $doctor->user->name }}? The invitation link will stop working.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-outline-danger w-100" type="submit">Cancel invitation</button>
                    </form>
                @endif
            </section>
        </div>

        <div class="col-lg-8">
            <form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('admin.doctors.update', $doctor) }}" enctype="multipart/form-data" data-disable-on-submit>
                @csrf @method('PATCH')
                <h2 class="h5 mb-3">Account details</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" value="{{ old('name', $doctor->user->name) }}" required maxlength="255"></div>
                    <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email', $doctor->user->email) }}" required maxlength="255"></div>
                    <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone', $doctor->user->phone) }}" maxlength="30"></div>
                </div>
                <h2 class="h5 mb-3">Professional profile</h2>
                <div class="row g-3">
                    <x-admin.doctor-profile-fields :specialties="$specialties" :doctor="$doctor" />
                    <x-doctors.photo-field :profile="$doctor" />
                </div>
                <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save doctor profile</button>
            </form>
        </div>
    </div>
</div>
@endsection
