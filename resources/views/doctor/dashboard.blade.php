@extends('layouts.app')

@section('title', 'Doctor dashboard | myDoc')

@section('content')
    @php($profile = $profile ?? null)

    <div class="container py-5">
        <div class="mb-5">
            <p class="hero__eyebrow mb-2">Doctor space</p>
            <h1 class="h2 mb-2">Welcome, {{ auth()->user()->name }}.</h1>
            <p class="text-secondary mb-0">Keep your professional profile and weekly availability up to date.</p>
        </div>

        <div class="row g-3 mb-5">
            @foreach (['total' => 'Appointments', 'booked' => 'Awaiting action', 'confirmed' => 'Confirmed'] as $key => $label)
                <div class="col-6 col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <p class="text-secondary mb-1">{{ $label }}</p>
                            <p class="h3 mb-0">{{ $appointmentCounts[$key] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card border-0 shadow-sm mb-5">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h4 mb-0">Recent appointments</h2>
                    <a href="{{ route('doctor.appointments.index') }}">Open appointment manager</a>
                </div>
                @forelse ($appointments as $appointment)
                    <div class="border-top py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <strong>{{ $appointment->patient->name }}</strong>
                            <div class="small text-secondary">{{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</div>
                            <x-appointments.consultation-type :type="$appointment->consultation_type" />
                        </div>
                        <x-ui.status-badge :status="$appointment->status" />
                    </div>
                @empty
                    <p class="text-secondary mb-0">Appointments will appear here after patients book your available slots.</p>
                @endforelse
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-4">Account details</h2>
                        <form method="POST" action="{{ route('doctor.account.update') }}">
                            @csrf @method('PUT')
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="doctor_name">Name</label>
                                    <input class="form-control" id="doctor_name" name="name" value="{{ old('name', auth()->user()->name) }}" required></div>
                                <div class="col-md-6"><label class="form-label" for="doctor_email">Email</label>
                                    <input class="form-control" id="doctor_email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" required></div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-6"><label class="form-label" for="doctor_phone">Phone</label>
                                    <input class="form-control" id="doctor_phone" name="phone" value="{{ old('phone', auth()->user()->phone) }}"></div>
                                <div class="col-md-6"><label class="form-label" for="doctor_address">Address</label>
                                    <input class="form-control" id="doctor_address" name="address" value="{{ old('address', auth()->user()->address) }}"></div>
                            </div>
                            <button class="btn btn-outline-primary mt-3">Save account details</button>
                        </form>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-4">Professional profile</h2>
                        <form method="POST" action="{{ route('doctor.profile.update') }}">
                            @csrf
                            @method('PUT')

                            <div class="mb-3">
                                <label class="form-label" for="professional_title">Professional title</label>
                                <input class="form-control @error('professional_title') is-invalid @enderror"
                                    id="professional_title" name="professional_title" type="text"
                                    value="{{ old('professional_title', $profile?->professional_title) }}" required>
                                @error('professional_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="bio">Biography</label>
                                <textarea class="form-control @error('bio') is-invalid @enderror" id="bio" name="bio"
                                    rows="4">{{ old('bio', $profile?->bio) }}</textarea>
                                @error('bio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="license_number">License number</label>
                                    <input class="form-control @error('license_number') is-invalid @enderror"
                                        id="license_number" name="license_number" type="text"
                                        value="{{ old('license_number', $profile?->license_number) }}" required>
                                    @error('license_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consultation_fee">Consultation fee</label>
                                    <input class="form-control @error('consultation_fee') is-invalid @enderror"
                                        id="consultation_fee" name="consultation_fee" type="number" min="0" step="0.01"
                                        value="{{ old('consultation_fee', $profile?->consultation_fee) }}" required>
                                    @error('consultation_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="mt-3 mb-3">
                                <label class="form-label" for="years_of_experience">Years of experience</label>
                                <input class="form-control @error('years_of_experience') is-invalid @enderror"
                                    id="years_of_experience" name="years_of_experience" type="number" min="0" max="100"
                                    value="{{ old('years_of_experience', $profile?->years_of_experience) }}">
                                @error('years_of_experience')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <fieldset class="mb-3">
                                <legend class="form-label fs-6">Specialties</legend>
                                <div class="row g-2">
                                    @foreach ($specialties as $specialty)
                                        <div class="col-sm-6">
                                            <label class="form-check">
                                                <input class="form-check-input" name="specialty_ids[]" type="checkbox"
                                                    value="{{ $specialty->id }}"
                                                    @checked(in_array($specialty->id, old('specialty_ids', $profile?->specialties->modelKeys() ?? [])))>
                                                <span class="form-check-label">{{ $specialty->name }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>

                            <div class="form-check mb-4">
                                <input class="form-check-input" id="is_accepting_patients" name="is_accepting_patients"
                                    type="checkbox" value="1" @checked(old('is_accepting_patients', $profile?->is_accepting_patients ?? true))>
                                <label class="form-check-label" for="is_accepting_patients">Accepting new patients</label>
                            </div>

                            <button class="btn btn-primary" type="submit">Save profile</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <x-forms.password-form />
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Generate appointment slots</h2>
                        <p class="small text-secondary">Slots use the application timezone ({{ config('app.timezone', 'UTC') }}).</p>
                        <form method="POST" action="{{ route('doctor.slots.generate') }}">
                            @csrf
                            <label class="form-label" for="days">Future days</label>
                            <input class="form-control mb-3 @error('days') is-invalid @enderror" id="days" name="days"
                                type="number" min="1" max="90" value="{{ old('days', 30) }}" required>
                            @error('days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <button class="btn btn-outline-primary" type="submit">Generate slots</button>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Weekly availability</h2>
                        <form method="POST" action="{{ route('doctor.availability.store') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="day_of_week">Day</label>
                                <select class="form-select" id="day_of_week" name="day_of_week" required>
                                    @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day)
                                        <option value="{{ $loop->index }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="start_time">Start</label>
                                    <input class="form-control" id="start_time" name="start_time" type="time" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="end_time">End</label>
                                    <input class="form-control" id="end_time" name="end_time" type="time" required>
                                </div>
                            </div>
                            <div class="mt-3 mb-3">
                                <label class="form-label" for="slot_duration_minutes">Slot duration (minutes)</label>
                                <input class="form-control" id="slot_duration_minutes" name="slot_duration_minutes"
                                    type="number" min="5" max="240" value="30" required>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" id="availability_active" name="is_active" type="checkbox"
                                    value="1" checked>
                                <label class="form-check-label" for="availability_active">Active</label>
                            </div>
                            <button class="btn btn-outline-primary" type="submit">Add availability</button>
                        </form>

                        @if ($profile?->availabilities->isNotEmpty())
                            <hr>
                            <ul class="list-group list-group-flush">
                                @foreach ($profile->availabilities as $availability)
                                    <li class="list-group-item px-0">
                                        <form class="row g-2 align-items-end" method="POST" action="{{ route('doctor.availability.update', $availability) }}">
                                            @csrf
                                            @method('PATCH')
                                            <div class="col-6">
                                                <label class="form-label small" for="availability-day-{{ $availability->id }}">Day</label>
                                                <select class="form-select form-select-sm" id="availability-day-{{ $availability->id }}" name="day_of_week" required>
                                                    @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day)
                                                        <option value="{{ $loop->index }}" @selected($availability->day_of_week === $loop->index)>{{ $day }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small" for="availability-duration-{{ $availability->id }}">Minutes</label>
                                                <input class="form-control form-control-sm" id="availability-duration-{{ $availability->id }}" name="slot_duration_minutes" type="number" min="5" max="240" value="{{ $availability->slot_duration_minutes }}" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small" for="availability-start-{{ $availability->id }}">Start</label>
                                                <input class="form-control form-control-sm" id="availability-start-{{ $availability->id }}" name="start_time" type="time" value="{{ substr($availability->start_time, 0, 5) }}" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small" for="availability-end-{{ $availability->id }}">End</label>
                                                <input class="form-control form-control-sm" id="availability-end-{{ $availability->id }}" name="end_time" type="time" value="{{ substr($availability->end_time, 0, 5) }}" required>
                                            </div>
                                            <div class="col-6 form-check ms-2">
                                                <input class="form-check-input" id="availability-active-{{ $availability->id }}" name="is_active" type="checkbox" value="1" @checked($availability->is_active)>
                                                <label class="form-check-label small" for="availability-active-{{ $availability->id }}">Active</label>
                                            </div>
                                            <div class="col-auto d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                            </div>
                                        </form>
                                        <form class="mt-2" method="POST" action="{{ route('doctor.availability.destroy', $availability) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove availability</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
