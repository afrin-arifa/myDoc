@extends('layouts.app')
@section('title', 'Manage assistant | myDoc')
@section('content')
<div class="container py-5">
    <a href="{{ route('admin.assistants.index') }}">&larr; Doctor assistants</a>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 my-4">
        <div>
            <h1 class="h2 mb-1">{{ $assistant->name }}</h1>
            <p class="text-secondary mb-0">Doctor Assistant · <x-admin.account-status :status="$status" :labels="$statusLabels" /></p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('admin.assistants.edit', $assistant) }}">Edit details</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="card border-0 shadow-sm p-4" aria-labelledby="account-heading">
                <h2 class="h5" id="account-heading">Account</h2>
                <dl class="mb-3">
                    <dt class="small text-secondary">Email</dt>
                    <dd>{{ $assistant->email }}</dd>
                    <dt class="small text-secondary">Mobile number</dt>
                    <dd>{{ $assistant->phone ?: '—' }}</dd>
                    <dt class="small text-secondary">Status</dt>
                    <dd><x-admin.account-status :status="$status" :labels="$statusLabels" /></dd>
                    @if ($invitationExpiresAt)
                        <dt class="small text-secondary">Invitation {{ $status === 'invitation_expired' ? 'expired' : 'expires' }}</dt>
                        <dd>{{ $invitationExpiresAt->format('M j, Y g:i A') }} ({{ config('app.timezone') }})</dd>
                    @endif
                    <dt class="small text-secondary">Created</dt>
                    <dd>{{ $assistant->created_at->format('M j, Y') }}</dd>
                </dl>

                @if ($canResendInvitation)
                    <form method="POST" action="{{ route('admin.assistants.invitation', $assistant) }}" class="mb-2" data-disable-on-submit>
                        @csrf
                        <button class="btn btn-primary w-100" type="submit" data-loading-text="Sending…">{{ $status === 'deactivated' ? 'Send new invitation' : 'Resend invitation' }}</button>
                    </form>
                @endif

                @if ($status === 'active')
                    <form method="POST" action="{{ route('admin.assistants.status', $assistant) }}"
                        data-confirm="Deactivate {{ $assistant->name }}? Every doctor assignment is removed (the history is kept) and they lose access immediately.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-outline-danger w-100" type="submit">Deactivate account</button>
                    </form>
                @elseif ($status === 'deactivated' && ! $canResendInvitation)
                    <form method="POST" action="{{ route('admin.assistants.status', $assistant) }}"
                        data-confirm="Reactivate {{ $assistant->name }}? Earlier doctor assignments are not restored.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="activate">
                        <button class="btn btn-success w-100" type="submit">Reactivate account</button>
                    </form>
                @elseif ($status !== 'deactivated')
                    <form method="POST" action="{{ route('admin.assistants.status', $assistant) }}"
                        data-confirm="Cancel the invitation for {{ $assistant->name }}? The invitation link will stop working.">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="deactivate">
                        <button class="btn btn-outline-danger w-100" type="submit">Cancel invitation</button>
                    </form>
                @endif
            </section>
        </div>

        <div class="col-lg-8">
            <section class="card border-0 shadow-sm p-4 mb-4" aria-labelledby="assignments-heading">
                <h2 class="h5" id="assignments-heading">Assigned doctors</h2>
                @if ($assignments->isEmpty())
                    <p class="text-secondary mb-3">Not assigned to any doctor.</p>
                @else
                    <div class="table-responsive mb-3">
                        <table class="table align-middle mb-0">
                            <caption class="visually-hidden">Doctors this assistant is assigned to</caption>
                            <thead><tr><th scope="col">Doctor</th><th scope="col">Assigned</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($assignments as $assignment)
                                    <tr>
                                        <td>
                                            <strong>{{ $assignment->doctorProfile->user->name }}</strong>
                                            @if ($assignment->doctorProfile->user->deactivated_at)
                                                <span class="badge text-bg-light ms-1">Doctor inactive</span>
                                            @endif
                                            <br><span class="small text-secondary">{{ $assignment->doctorProfile->user->email }}</span>
                                        </td>
                                        <td class="small">{{ $assignment->created_at->format('M j, Y') }}@if ($assignment->assignedBy)<br><span class="text-secondary">by {{ $assignment->assignedBy->name }}</span>@endif</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('admin.assistants.assignments.destroy', [$assistant, $assignment->doctorProfile]) }}"
                                                data-confirm="Remove {{ $assistant->name }} from {{ $assignment->doctorProfile->user->name }}? Access ends immediately.">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Remove<span class="visually-hidden"> {{ $assignment->doctorProfile->user->name }}</span></button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($status !== 'active')
                    <p class="small text-secondary mb-0">Doctors can be assigned once the assistant's account is active.</p>
                @elseif ($availableDoctors->isEmpty())
                    <p class="small text-secondary mb-0">No other active doctors are available to assign.</p>
                @else
                    <form method="POST" action="{{ route('admin.assistants.assignments.store', $assistant) }}" data-disable-on-submit>
                        @csrf
                        <fieldset>
                            <legend class="h6">Assign doctors</legend>
                            <div class="row row-cols-1 row-cols-md-2 g-2 mb-3">
                                @foreach ($availableDoctors as $doctor)
                                    <div class="col">
                                        <div class="form-check">
                                            <input class="form-check-input" id="doctor_{{ $doctor->id }}" name="doctor_profile_ids[]" type="checkbox" value="{{ $doctor->id }}"
                                                @checked(in_array((string) $doctor->id, array_map('strval', (array) old('doctor_profile_ids', [])), true))>
                                            <label class="form-check-label" for="doctor_{{ $doctor->id }}">{{ $doctor->user->name }} <span class="small text-secondary">({{ $doctor->user->email }})</span></label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        @error('doctor_profile_ids')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                        <button class="btn btn-primary" type="submit" data-loading-text="Assigning…">Assign selected doctors</button>
                    </form>
                @endif
            </section>

            <section class="card border-0 shadow-sm p-4" aria-labelledby="history-heading">
                <h2 class="h5" id="history-heading">Assignment history</h2>
                @if ($history->isEmpty())
                    <p class="text-secondary mb-0">No assignment changes yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <caption class="visually-hidden">Assignment history, newest first</caption>
                            <thead><tr><th scope="col">When</th><th scope="col">Change</th><th scope="col">Doctor</th><th scope="col">By</th></tr></thead>
                            <tbody>
                                @foreach ($history as $event)
                                    <tr>
                                        <td class="small text-nowrap">{{ $event->created_at->format('M j, Y g:i A') }}</td>
                                        <td><span class="badge {{ $event->action === 'assigned' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($event->action) }}</span></td>
                                        <td>{{ $event->doctorProfile?->user?->name ?? 'Doctor #'.$event->doctor_profile_id }}</td>
                                        <td>{{ $event->actor?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $history->links() }}
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
