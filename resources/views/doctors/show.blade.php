@extends('layouts.app')

@section('title', $doctor->user->name.' | myDoc')

@section('content')
    <div class="container py-5">
        <a class="back-link mb-3" href="{{ route('doctors.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>All doctors</a>

        <header class="doctor-detail__hero">
            {{-- Decorative: the doctor's name is the heading beside it. --}}
            <img class="doctor-detail__photo" src="{{ $doctor->photoUrl() ?? asset('images/doctor.png') }}" alt="" width="192" height="192" decoding="async">
            <div class="doctor-detail__intro">
                <p class="doctor-detail__title">{{ $doctor->professional_title }}</p>
                <h1 class="doctor-detail__name">{{ $doctor->user->name }}</h1>
                @if ($doctor->qualifications)
                    <p class="doctor-detail__qualifications"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span class="visually-hidden">Qualifications: </span>{{ $doctor->qualifications }}</p>
                @endif
                @if ($doctor->specialties->isNotEmpty())
                    <ul class="doctor-detail__specialties" aria-label="Specialties">
                        @foreach ($doctor->specialties as $specialty)
                            <li>{{ $specialty->name }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <dl class="doctor-detail__facts">
                <div><dt>Experience</dt><dd>{{ $doctor->years_of_experience !== null ? $doctor->years_of_experience.' '.Str::plural('year', $doctor->years_of_experience) : 'Not specified' }}</dd></div>
                <div><dt>Consultation fee</dt><dd>{{ App\Services\ConsultationFee::format($doctor->consultation_fee) }}</dd></div>
                @if ($doctor->follow_up_fee !== null)
                    <div><dt>Follow-up fee</dt><dd>{{ App\Services\ConsultationFee::format($doctor->follow_up_fee) }}</dd></div>
                @endif
            </dl>
        </header>

        <div class="doctor-detail__layout">
            <div class="doctor-detail__about">
                @if (filled($doctor->bio))
                    <section class="doctor-panel" aria-labelledby="about-heading">
                        <div class="doctor-profile-section"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i><h2 class="doctor-panel__title" id="about-heading">About</h2></div>
                        <p class="doctor-detail__bio">{{ $doctor->bio }}</p>
                    </section>
                @endif
                @if ($locations->isNotEmpty())
                    <section class="doctor-panel" aria-labelledby="locations-heading">
                        <div class="doctor-profile-section"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><h2 class="doctor-panel__title" id="locations-heading">{{ Str::plural('Practice location', $locations->count()) }}</h2></div>
                        <ul class="doctor-detail__locations" aria-label="Practice locations">
                            @foreach ($locations as $location)
                                <li>
                                    <strong>{{ $location->name }}</strong>
                                    <span>{{ collect([$location->address, $location->area])->filter()->join(', ') ?: 'Address not provided yet' }}</span>
                                    @if ($location->phone)
                                        <span><i class="fa-solid fa-phone" aria-hidden="true"></i> {{ $location->phone }}</span>
                                    @endif
                                    @if ($location->directions)
                                        <span>{{ $location->directions }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
            <div class="doctor-detail__booking">
                <section class="doctor-panel" id="availability" aria-labelledby="availability-heading">
                    <div>
                        <div class="doctor-profile-section"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="availability-heading">Available times</h2><p>Times are shown in Bangladesh time (Asia/Dhaka). Choose a day to see its times.</p></div></div>

                        @if ($followUpOf)
                            <div class="alert alert-info small" role="status" data-follow-up-banner>
                                Booking a follow-up to your appointment {{ $followUpOf->reference() }} on {{ $followUpOf->slot->starts_at->format('D, M j, Y') }}. Choose a time below.
                                <a class="alert-link" href="{{ route('doctors.show', $doctor) }}">Book a regular appointment instead</a>
                            </div>
                        @endif

                        @if ($canBook && $hasReachedLimit)
                            <div class="alert alert-warning small" role="status" id="booking-limit-notice">
                                You already have {{ $activeLimit }} active appointment requests (pending or confirmed), which is the maximum.
                                Cancel one, or wait until one has taken place, before requesting another.
                                <a class="alert-link" href="{{ route('patient.appointments.index') }}">View my appointments</a>
                            </div>
                        @endif

                        @if ($locations->count() > 1)
                            <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Choose a location">
                                <a @class(['btn btn-sm', 'btn-primary' => ! $selectedLocation, 'btn-outline-primary' => $selectedLocation]) href="{{ route('doctors.show', array_filter(['doctor' => $doctor, 'date' => $selectedDate->toDateString(), 'follow_up' => $followUpOf?->getRouteKey()])) }}#availability" @if (! $selectedLocation) aria-current="true" @endif>All locations</a>
                                @foreach ($locations as $location)
                                    <a @class(['btn btn-sm', 'btn-primary' => $selectedLocation?->is($location), 'btn-outline-primary' => ! $selectedLocation?->is($location)]) href="{{ route('doctors.show', array_filter(['doctor' => $doctor, 'date' => $selectedDate->toDateString(), 'location' => $location->getRouteKey(), 'follow_up' => $followUpOf?->getRouteKey()])) }}#availability" @if ($selectedLocation?->is($location)) aria-current="true" @endif>{{ $location->name }}</a>
                                @endforeach
                            </nav>
                        @endif

                        <nav class="availability-days mb-3" aria-label="Choose a day">
                            <ul class="availability-days__list">
                                @foreach ($days as $day)
                                    @php
                                        $isSelected = $day['date']->isSameDay($selectedDate);
                                    @endphp
                                    <li>
                                        <a @class(['availability-day', 'is-selected' => $isSelected, 'is-empty' => $day['openSlots'] === 0])
                                            href="{{ route('doctors.show', array_filter(['doctor' => $doctor, 'date' => $day['date']->toDateString(), 'location' => $selectedLocation?->getRouteKey(), 'follow_up' => $followUpOf?->getRouteKey()])) }}#availability"
                                            @if ($isSelected) aria-current="date" @endif
                                            aria-label="{{ $day['date']->format('l, F j') }}: {{ $day['openSlots'] === 0 ? 'no open times' : $day['openSlots'].' open '.Str::plural('time', $day['openSlots']) }}">
                                            <span class="availability-day__weekday">{{ $day['date']->isToday() ? 'Today' : $day['date']->format('D') }}</span>
                                            <span class="availability-day__date">{{ $day['date']->day }}</span>
                                            <span class="availability-day__month">{{ $day['date']->format('M') }}</span>
                                            <span class="availability-day__count" aria-hidden="true">@if ($day['openSlots'] === 0)–@else{{ $day['openSlots'] }}<span class="availability-day__open"> open</span>@endif</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>

                        <h3 class="doctor-detail__day">{{ $selectedDate->format('l, M j, Y') }}@if ($slots->isNotEmpty())<span>{{ $slots->count() }} open {{ Str::plural('time', $slots->count()) }}</span>@endif</h3>

                        @if ($slots->isEmpty())
                            <div class="availability-empty">
                                <p class="text-secondary mb-2">No open times on this day.</p>
                                @if ($nextOpenSlotStart)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('doctors.show', array_filter(['doctor' => $doctor, 'date' => $nextOpenSlotStart->toDateString(), 'location' => $selectedLocation?->getRouteKey(), 'follow_up' => $followUpOf?->getRouteKey()])) }}#availability">
                                        Next open time: {{ $nextOpenSlotStart->format('D, M j, g:i A') }}
                                    </a>
                                @else
                                    <p class="small text-secondary mb-0">This doctor has no future open times listed right now.</p>
                                @endif
                            </div>
                        @else
                            @foreach ($slots->groupBy(fn ($slot) => $slot->doctor_location_id ?? 0) as $locationSlots)
                            @php
                                $slotLocation = $locationSlots->first()->location;
                                $locationLabel = $slotLocation?->name ?? 'Location not specified';
                            @endphp
                            <h4 class="h6 mt-2 mb-1"><i class="fa-solid fa-location-dot text-secondary me-1" aria-hidden="true"></i>{{ $locationLabel }}</h4>
                            @if ($slotLocation?->address)
                                <p class="small text-secondary mb-2">{{ collect([$slotLocation->address, $slotLocation->area])->filter()->join(', ') }}</p>
                            @endif
                            @foreach ($locationSlots->groupBy(fn ($slot) => match (true) { $slot->starts_at->hour < 12 => 'Morning', $slot->starts_at->hour < 17 => 'Afternoon', default => 'Evening' }) as $period => $periodSlots)
                            <p class="doctor-detail__period"><i class="{{ ['Morning' => 'fa-regular fa-sun', 'Afternoon' => 'fa-solid fa-cloud-sun', 'Evening' => 'fa-regular fa-moon'][$period] }}" aria-hidden="true"></i>{{ $period }}</p>
                            <ul class="slot-grid" aria-label="{{ $period }} times at {{ $locationLabel }} on {{ $selectedDate->format('l, F j, Y') }}">
                                @foreach ($periodSlots as $slot)
                                    @php
                                        $time = $slot->starts_at->format('g:i A');
                                        $fullTime = $slot->starts_at->format('D, M j, Y').' at '.$time.($slotLocation ? ' at '.$slotLocation->name : '');
                                        $overlap = $overlaps[$slot->id] ?? null;
                                    @endphp
                                    <li>
                                        @if (! $canBook)
                                            <span class="slot-time slot-time--static">{{ $time }}</span>
                                        @elseif ($overlap)
                                            <span class="slot-time is-disabled" aria-disabled="true">
                                                <span>{{ $time }}</span>
                                                <small>Overlaps your appointment at {{ $overlap->slot->starts_at->format('g:i A') }}</small>
                                                <span class="visually-hidden">: {{ $fullTime }} is unavailable because it overlaps your appointment on {{ $overlap->slot->starts_at->format('D, M j, Y \a\t g:i A') }}.</span>
                                            </span>
                                        @elseif ($hasReachedLimit)
                                            <span class="slot-time is-disabled" aria-disabled="true" aria-describedby="booking-limit-notice">
                                                <span>{{ $time }}</span>
                                                <span class="visually-hidden">: {{ $fullTime }} cannot be requested while you are at the limit.</span>
                                            </span>
                                        @else
                                            <a class="slot-time" href="{{ route('appointment-slots.confirm', array_filter([$slot, 'follow_up' => $followUpOf?->getRouteKey()])) }}" aria-label="Book {{ $fullTime }}">{{ $time }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @endforeach
                            @endforeach
                        @endif

                        @if ($waitlistEntry)
                            <div class="alert alert-light mt-4 mb-0 small" role="status" data-waitlist-status>
                                You are on {{ $doctor->user->name }}'s waiting list ({{ $waitlistEntry->statusLabel() }}).
                                <a class="alert-link" href="{{ route('patient.waitlist.index') }}">View your waiting list</a>
                            </div>
                        @elseif ($canJoinWaitlist)
                            @php($waitFrom = $selectedDate->isPast() && ! $selectedDate->isToday() ? today() : $selectedDate)
                            <details class="doctor-detail__waitlist" @if ($errors->hasAny(['waitlist', 'date_from', 'date_to', 'time_of_day', 'doctor_location_id'])) open @endif data-waitlist-join>
                                <summary><i class="fa-regular fa-bell" aria-hidden="true"></i><span>None of these times suit you? Join the waiting list</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
                                <form class="row g-3" method="POST" action="{{ route('patient.waitlist.store', $doctor) }}" data-disable-on-submit>
                                    @csrf
                                    @if ($followUpOf)<input type="hidden" name="follow_up_of_id" value="{{ $followUpOf->id }}">@endif
                                    <div class="col-sm-6"><label class="form-label" for="waitlist_date_from">From</label><input class="form-control @error('date_from') is-invalid @enderror" id="waitlist_date_from" name="date_from" type="date" min="{{ today()->toDateString() }}" value="{{ old('date_from', $waitFrom->toDateString()) }}" required></div>
                                    <div class="col-sm-6"><label class="form-label" for="waitlist_date_to">Until</label><input class="form-control @error('date_to') is-invalid @enderror" id="waitlist_date_to" name="date_to" type="date" min="{{ today()->toDateString() }}" value="{{ old('date_to', $waitFrom->addDays(6)->toDateString()) }}" required></div>
                                    <div class="col-sm-6"><label class="form-label" for="waitlist_time_of_day">Time of day</label><select class="form-select" id="waitlist_time_of_day" name="time_of_day"><option value="">Any time</option>@foreach (App\Models\WaitlistEntry::TIMES_OF_DAY as $value => [, , $label])<option value="{{ $value }}" @selected(old('time_of_day') === $value)>{{ $label }}</option>@endforeach</select></div>
                                    <div class="col-sm-6"><label class="form-label" for="waitlist_location">Location</label><select class="form-select" id="waitlist_location" name="doctor_location_id"><option value="">Any location</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((int) old('doctor_location_id', $selectedLocation?->id) === $location->id)>{{ $location->name }}</option>@endforeach</select></div>
                                    @if (count(App\Models\Appointment::bookableConsultationTypes()) > 1)
                                        <div class="col-sm-6"><label class="form-label" for="waitlist_consultation_type">Consultation</label><select class="form-select" id="waitlist_consultation_type" name="consultation_type">@foreach (App\Models\Appointment::bookableConsultationTypes() as $type)<option value="{{ $type }}">{{ $type === 'physical' ? 'Physical visit' : ucfirst($type).' call' }}</option>@endforeach</select></div>
                                    @else
                                        <input type="hidden" name="consultation_type" value="{{ App\Models\Appointment::bookableConsultationTypes()[0] }}">
                                    @endif
                                    <div class="col-12"><p class="small text-secondary mb-2">Up to {{ config('booking.waitlist.max_range_days') }} days. When a matching time opens, it is held for you for up to {{ config('booking.waitlist.hold_minutes') }} minutes and we let you know.</p><button class="btn btn-primary" type="submit">Join the waiting list</button></div>
                                </form>
                            </details>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
