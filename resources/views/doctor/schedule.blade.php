@extends('layouts.app')

@section('title', ($workspace->inAssistantWorkspace() ? 'Schedule for '.$profile->user->name : 'Schedule Timing').' | myDoc')

@section('doctor_workspace')
@endsection

@section('content')
    @php
        $weekStart = $selectedDate->startOfWeek(Carbon\Carbon::MONDAY);
        $monthStart = $selectedDate->startOfMonth();
        $monthOffset = $monthStart->dayOfWeekIso - 1;
        $calendarStart = $view === 'day' ? $selectedDate : $weekStart;
        $calendarDayCount = $view === 'day' ? 1 : 7;
        $timelineStartHour = 8;
        $timelineEndHour = 18;
        $timelineMinutes = ($timelineEndHour - $timelineStartHour) * 60;
        $timelinePixelsPerMinute = 1;
        $activeFilters = $activeFilters ?? ['available', 'held', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $scheduleQuery = ['view' => $view, 'filters' => $activeFilters];
        $statusFilters = [
            'available' => ['Available slot', 'green'],
            'held' => ['Held for waiting list', 'purple'],
            'blocked' => ['Blocked slot', 'gray'],
            'booked' => [App\Models\Appointment::statusLabel('booked'), 'orange'],
            'confirmed' => ['Confirmed', 'blue'],
            'cancelled' => ['Cancelled, rejected or expired', 'red'],
        ];
    @endphp

    <div class="doctor-workspace doctor-schedule-page">
        <div class="container-fluid doctor-workspace__container">
            <div class="row g-4">
                <div class="col-xl-3 col-xxl-2">
                    <x-doctors.profile-summary :profile="$profile" active="schedule" :pending-count="$pendingCount" />
                </div>

                <div class="col-xl-9 col-xxl-10">
                    <header class="doctor-page-header doctor-schedule-header">
                        <div>
                            @if ($workspace->inAssistantWorkspace())
                                <p class="doctor-eyebrow"><i class="fa-regular fa-calendar me-2"></i>Doctor Assistant</p>
                                <h1 class="doctor-page-title">{{ $profile->user->name }}'s schedule</h1>
                                <p class="text-secondary mb-0">Manage this doctor's availability, appointments, and consultation slots.</p>
                            @else
                                <p class="doctor-eyebrow"><i class="fa-regular fa-calendar me-2"></i>Doctor workspace</p>
                                <h1 class="doctor-page-title">My Schedule</h1>
                                <p class="text-secondary mb-0">Manage your availability, appointments, and consultation slots.</p>
                            @endif
                        </div>
                        <div class="doctor-schedule-header__actions">
                            <a class="btn btn-primary" href="{{ $workspace->url('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}">
                                <i class="fa-solid fa-plus me-1"></i> New Appointment
                            </a>
                            <button class="doctor-icon-button" type="button" aria-label="Notifications">
                                <i class="fa-regular fa-bell"></i>
                            </button>
                            @if ($workspace->routeAvailable('doctor.settings'))
                                <a class="doctor-icon-button" href="{{ route('doctor.settings') }}" aria-label="Account settings">
                                    <i class="fa-regular fa-user"></i>
                                </a>
                            @endif
                        </div>
                    </header>

                    @php
                        $activeLocations = $profile->locations->where('is_active', true)->values();
                    @endphp
                    <nav class="doctor-schedule-tabs" aria-label="Schedule sections" data-initial-schedule-tab="{{ old('location_form') ? 'locations' : ($errors->has('doctor_location_id') ? 'availability' : '') }}">
                        <a class="is-active" href="#schedule-panel" data-schedule-tab="schedule"><i class="fa-regular fa-calendar me-2"></i>Schedule</a>
                        <a href="#locations-panel" data-schedule-tab="locations"><i class="fa-solid fa-location-dot me-2"></i>Locations</a>
                        <a href="#availability-panel" data-schedule-tab="availability"><i class="fa-solid fa-layer-group me-2"></i>Availability</a>
                        <a href="#generate-panel" data-schedule-tab="generate"><i class="fa-solid fa-layer-group me-2"></i>Generate Slots</a>
                        <a href="#waitlist-panel" data-schedule-tab="waitlist"><i class="fa-solid fa-user-clock me-2"></i>Waiting list @if ($waitlistEntries->isNotEmpty())<span class="badge text-bg-light">{{ $waitlistEntries->count() }}</span>@endif</a>
                    </nav>

                    <section id="schedule-panel" data-schedule-panel="schedule" class="doctor-schedule-tab-panel">
                        <div class="doctor-schedule-workspace">
                            <aside class="doctor-panel doctor-filter-panel">
                                <div class="doctor-panel__heading">
                                    <h2 class="doctor-panel__title">Calendar Filters</h2>
                                    <i class="fa-solid fa-filter text-primary"></i>
                                </div>

                                <div class="doctor-filter-list">
                                    @foreach ($statusFilters as $filter => [$label, $color])
                                        <label class="doctor-filter-option">
                                            <input class="schedule-filter" type="checkbox" value="{{ $filter }}" {{ in_array($filter, $activeFilters, true) ? 'checked' : '' }}>
                                            <span class="doctor-filter-check"></span>
                                            <span class="doctor-filter-swatch doctor-filter-swatch--{{ $color }}"></span>
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                    <label class="doctor-filter-option">
                                        <input class="schedule-consultation-filter" type="checkbox" checked>
                                        <span class="doctor-filter-check"></span>
                                        <span class="doctor-filter-swatch doctor-filter-swatch--purple"></span>
                                        <span>Consultation</span>
                                    </label>
                                </div>

                                <div class="doctor-mini-calendar">
                                    <div class="doctor-mini-calendar__header">
                                        <a href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->subMonth()->toDateString()]) }}" aria-label="Previous month">&lsaquo;</a>
                                        <strong>{{ $selectedDate->format('F Y') }}</strong>
                                        <a href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->addMonth()->toDateString()]) }}" aria-label="Next month">&rsaquo;</a>
                                    </div>
                                    <div class="doctor-mini-calendar__grid">
                                        @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $day)
                                            <small>{{ $day }}</small>
                                        @endforeach
                                        @for ($blank = 0; $blank < $monthOffset; $blank++)
                                            <span></span>
                                        @endfor
                                        @for ($day = 1; $day <= $selectedDate->daysInMonth; $day++)
                                            @php
                                                $miniDate = $monthStart->day($day);
                                            @endphp
                                            <a class="{{ $miniDate->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $miniDate->isToday() ? 'is-today' : '' }}" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $miniDate->toDateString()]) }}">{{ $day }}</a>
                                        @endfor
                                    </div>
                                </div>
                            </aside>

                            <section class="doctor-panel doctor-calendar-panel">
                                <div class="doctor-calendar-toolbar">
                                    <div class="doctor-calendar-toolbar__controls">
                                        <a class="doctor-square-button" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->subWeek()->toDateString()]) }}" aria-label="Previous week">&lsaquo;</a>
                                        <a class="doctor-square-button" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->addWeek()->toDateString()]) }}" aria-label="Next week">&rsaquo;</a>
                                        <a class="btn btn-sm btn-outline-primary" href="{{ $workspace->url('doctor.schedule') }}">Today</a>
                                    </div>
                                    <strong>
                                        @if ($view === 'day')
                                            {{ $selectedDate->format('D, M j, Y') }}
                                        @else
                                            {{ $weekStart->format('M j') }} – {{ $selectedDate->endOfWeek()->format('M j, Y') }}
                                        @endif
                                    </strong>
                                    <div class="btn-group btn-group-sm doctor-view-switcher" role="group" aria-label="Calendar view">
                                        @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $mode => $label)
                                            <a class="btn {{ $view === $mode ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->toDateString(), 'view' => $mode]) }}">{{ $label }}</a>
                                        @endforeach
                                    </div>
                                </div>

                                @if ($view === 'month')
                                    <div class="doctor-month-calendar">
                                        @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName)
                                            <strong>{{ $dayName }}</strong>
                                        @endforeach
                                        @foreach (range(0, 41) as $monthDayOffset)
                                            @php
                                                $monthDay = $monthStart->startOfWeek(Carbon\Carbon::MONDAY)->addDays($monthDayOffset);
                                                $monthDaySlots = $slotsByDate[$monthDay->toDateString()] ?? collect();
                                            @endphp
                                            <a class="{{ $monthDay->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $monthDay->month !== $selectedDate->month ? 'is-muted' : '' }}" data-calendar-date="{{ $monthDay->toDateString() }}" href="{{ $workspace->url('doctor.schedule', ['date' => $monthDay->toDateString(), 'view' => 'day']) }}">
                                                <strong>{{ $monthDay->day }}</strong>
                                                @foreach ($monthDaySlots->take(3) as $monthSlot)
                                                    <span class="doctor-month-calendar__dot doctor-month-calendar__dot--{{ $monthSlot->appointments->first()?->status ?? 'available' }}"></span>
                                                @endforeach
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                <div class="doctor-calendar-grid">
                                    <div class="doctor-calendar-grid__times">
                                        <span></span>
                                        @foreach (range($timelineStartHour, $timelineEndHour) as $hour)
                                            <span>{{ Carbon\Carbon::createFromTime($hour)->format('g:i A') }}</span>
                                        @endforeach
                                    </div>
                                    <div class="doctor-calendar-grid__days">
                                        @foreach (range(0, $calendarDayCount - 1) as $dayOffset)
                                            @php
                                                $day = $calendarStart->addDays($dayOffset);
                                                $dayEvents = $calendarEventsByDate[$day->toDateString()] ?? collect();
                                            @endphp
                                            <div class="doctor-calendar-day {{ $day->isSameDay($selectedDate) ? 'is-selected' : '' }}">
                                                <header>
                                                    <strong>{{ $day->format('D') }}</strong>
                                                    <span>{{ $day->format('M j') }}</span>
                                                </header>
                                                <div class="doctor-calendar-day__body">
                                                    <div class="doctor-calendar-day__lines"></div>
                                                        @php
                                                            $timelineStart = $day->startOfDay()->setTime($timelineStartHour, 0);
                                                        @endphp
                                                        @foreach ($dayEvents as $event)
                                                        @php
                                                                $eventStart = Carbon\CarbonImmutable::parse($event['starts_at']);
                                                                $eventEnd = Carbon\CarbonImmutable::parse($event['ends_at']);
                                                                $status = $event['filter_status'];
                                                                $minutesFromStart = $timelineStart->diffInMinutes($eventStart, false);
                                                                $durationMinutes = max(1, $eventStart->diffInMinutes($eventEnd));
                                                                $eventTop = max(0, $minutesFromStart) * $timelinePixelsPerMinute;
                                                                $eventHeight = min(
                                                                    max(10, $durationMinutes * $timelinePixelsPerMinute),
                                                                    max(10, ($timelineMinutes - max(0, $minutesFromStart)) * $timelinePixelsPerMinute)
                                                                );
                                                            @endphp
                                                            @continue($minutesFromStart >= $timelineMinutes || $eventEnd->lessThanOrEqualTo($timelineStart))
                                                            @php
                                                                $eventLabel = $event['patient_name'] ?? match ($status) { 'available' => 'Available', 'held' => 'Held (waiting list)', 'blocked' => 'Blocked', default => ucfirst($event['appointment_status']) };
                                                                $eventTime = $eventStart->format('g:i A').' - '.$eventEnd->format('g:i A');
                                                        @endphp
                                                        <article
                                                                class="doctor-calendar-event doctor-calendar-event--{{ $status }}"
                                                                data-schedule-status="{{ $status }}"
                                                                    data-schedule-kind="{{ $event['type'] === 'appointment' ? 'consultation' : 'available' }}"
                                                                    data-event-type="{{ $event['type'] }}"
                                                                    data-event-date="{{ $event['date'] }}"
                                                                    data-appointment-status="{{ $event['appointment_status'] }}"
                                                                    title="{{ $eventLabel }} ({{ $eventTime }}){{ ($event['location'] ?? null) ? ' · '.$event['location'] : '' }}"
                                                                    style="--event-top: {{ $eventTop }}px; --event-height: {{ $eventHeight }}px;"
                                                                >
                                                                    <span class="doctor-calendar-event__indicator" aria-hidden="true"></span>
                                                                    <strong>{{ $eventLabel }}</strong>
                                                                    <small>{{ $eventTime }}</small>
                                                                    @if ($event['appointment_id'])
                                                                        <a class="doctor-calendar-event__link" href="{{ $workspace->url('doctor.appointments.show', $event['appointment_id']) }}" aria-label="View appointment details">View</a>
                                                                        @if ($event['can_confirm'])
                                                                            <form method="POST" action="{{ $workspace->url('doctor.appointments.confirm', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Confirm appointment">Confirm</button>
                                                                            </form>
                                                                            <form method="POST" action="{{ $workspace->url('doctor.appointments.reject', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action doctor-calendar-event__action--danger" type="submit" aria-label="Reject appointment">Reject</button>
                                                                            </form>
                                                                        @elseif ($event['can_start'] && $workspace->routeAvailable('doctor.appointments.start'))
                                                                            <form method="POST" action="{{ route('doctor.appointments.start', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Start consultation">Start</button>
                                                                            </form>
                                                                        @endif
                                                                    @else
                                                                        @if ($event['type'] === 'available')
                                                                            <a class="doctor-calendar-event__link" href="{{ $workspace->url('doctor.appointments.create', ['date' => $event['date'], 'slot_id' => $event['slot_id']]) }}" aria-label="Book this available slot">Book</a>
                                                                        @endif
                                                                        @if ($event['can_block'] ?? false)
                                                                            <form method="POST" action="{{ $workspace->url('doctor.slots.block', $event['slot_id']) }}" data-confirm="Block this slot? Patients will not be able to book it.">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Block this slot">Block</button>
                                                                            </form>
                                                                        @elseif ($event['can_unblock'] ?? false)
                                                                            <form method="POST" action="{{ $workspace->url('doctor.slots.unblock', $event['slot_id']) }}">
                                                                                @csrf @method('DELETE')
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Unblock this slot">Unblock</button>
                                                                            </form>
                                                                        @endif
                                                                    @endif
                                                            </article>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif
                            </section>

                            <aside class="doctor-schedule-summary">
                                <section class="doctor-panel">
                                    <div class="doctor-panel__heading">
                                        <div>
                                            <h2 class="doctor-panel__title">Today’s Summary</h2>
                                            <small class="text-secondary">{{ $selectedDate->format('D, M j') }}</small>
                                        </div>
                                        <i class="fa-solid fa-chart-simple text-primary"></i>
                                    </div>
                                    <div class="doctor-summary-grid">
                                        @foreach ($summary as $label => $value)
                                            <div class="doctor-summary-item">
                                                <strong>{{ $value }}</strong>
                                                <small>{{ ucfirst($label) }}</small>
                                            </div>
                                        @endforeach
                                    </div>
                                </section>

                                <section class="doctor-panel">
                                    <h2 class="doctor-panel__title mb-3">Quick Actions</h2>
                                    <a class="doctor-quick-action" href="{{ $workspace->url('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}"><i class="fa-solid fa-plus"></i><span>New Appointment</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#generate-panel" data-open-schedule-tab="generate"><i class="fa-solid fa-layer-group"></i><span>Generate Slots</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#availability-panel" data-open-schedule-tab="availability"><i class="fa-regular fa-calendar"></i><span>Manage Availability</span><b>&rsaquo;</b></a>
                                </section>

                                <section class="doctor-panel">
                                    <div class="doctor-panel__heading">
                                        <h2 class="doctor-panel__title">Today’s Appointments</h2>
                                        <a href="{{ $workspace->url('doctor.appointments.index') }}">View All &rsaquo;</a>
                                    </div>
                                    @forelse ($selectedAppointments as $appointment)
                                        <div class="doctor-day-appointment">
                                            <strong>{{ $appointment->slot->starts_at->format('g:i A') }}</strong>
                                            <span>{{ $appointment->patient->name }}</span>
                                            <x-ui.status-badge :status="$appointment->status" />
                                        </div>
                                    @empty
                                        <p class="text-secondary small mb-0">No appointments for this day.</p>
                                    @endforelse
                                </section>
                            </aside>
                        </div>
                    </section>

                    <section id="locations-panel" data-schedule-panel="locations" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <div class="doctor-panel__heading">
                                <div><p class="doctor-eyebrow mb-1">Practice locations</p><h2 class="doctor-panel__title">Chambers</h2></div>
                                <span class="badge text-bg-light">{{ $activeLocations->count() }} active</span>
                            </div>
                            <p class="text-secondary">Patients see the location of each appointment time before they book. Weekly availability is held at one of these locations.</p>
                            @forelse ($profile->locations as $location)
                                <div class="border rounded-3 p-3 mb-3" data-location-row="{{ $location->id }}">
                                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                        <div>
                                            <strong>{{ $location->name }}</strong>
                                            @unless ($location->is_active)<span class="badge text-bg-secondary ms-1">Archived</span>@endunless
                                            <div class="small text-secondary">{{ $location->address ?? 'Address not provided yet' }}{{ $location->area ? ', '.$location->area : '' }}{{ $location->phone ? ' · '.$location->phone : '' }}</div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 align-items-start">
                                            <form method="POST" action="{{ $workspace->url('doctor.locations.status', $location) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $location->is_active ? 0 : 1 }}"><button class="btn btn-sm btn-outline-secondary" type="submit">{{ $location->is_active ? 'Archive' : 'Restore' }}</button></form>
                                            <form method="POST" action="{{ $workspace->url('doctor.locations.destroy', $location) }}" data-confirm="Delete {{ $location->name }}? Only locations that were never used can be deleted.">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger" type="submit">Delete</button></form>
                                        </div>
                                    </div>
                                    <details>
                                        <summary class="small">Edit details</summary>
                                        <form class="row g-2 mt-1" method="POST" action="{{ $workspace->url('doctor.locations.update', $location) }}">
                                            @csrf @method('PATCH')
                                            <div class="col-md-6"><label class="form-label small" for="location-{{ $location->id }}-name">Name</label><input class="form-control form-control-sm" id="location-{{ $location->id }}-name" name="name" value="{{ $location->name }}" maxlength="120" required></div>
                                            <div class="col-md-6"><label class="form-label small" for="location-{{ $location->id }}-address">Address</label><input class="form-control form-control-sm" id="location-{{ $location->id }}-address" name="address" value="{{ $location->address }}" maxlength="255" required></div>
                                            <div class="col-md-4"><label class="form-label small" for="location-{{ $location->id }}-area">Area</label><input class="form-control form-control-sm" id="location-{{ $location->id }}-area" name="area" value="{{ $location->area }}" maxlength="100"></div>
                                            <div class="col-md-4"><label class="form-label small" for="location-{{ $location->id }}-phone">Phone</label><input class="form-control form-control-sm" id="location-{{ $location->id }}-phone" name="phone" type="tel" value="{{ $location->phone }}" maxlength="20"></div>
                                            <div class="col-md-4"><label class="form-label small" for="location-{{ $location->id }}-directions">Directions</label><input class="form-control form-control-sm" id="location-{{ $location->id }}-directions" name="directions" value="{{ $location->directions }}" maxlength="500"></div>
                                            <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save location</button></div>
                                        </form>
                                    </details>
                                </div>
                            @empty
                                <p class="text-secondary">No locations yet. Add the chamber where you see patients before adding weekly availability.</p>
                            @endforelse
                            <h3 class="h6 mt-4">Add a location</h3>
                            <form class="row g-3" method="POST" action="{{ $workspace->url('doctor.locations.store') }}">
                                @csrf
                                <input type="hidden" name="location_form" value="1">
                                <div class="col-md-6"><label class="form-label" for="new-location-name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="new-location-name" name="name" value="{{ old('location_form') ? old('name') : '' }}" maxlength="120" placeholder="e.g. Popular Diagnostic, Dhanmondi" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="new-location-address">Address</label><input class="form-control @error('address') is-invalid @enderror" id="new-location-address" name="address" value="{{ old('location_form') ? old('address') : '' }}" maxlength="255" required>@error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="new-location-area">Area</label><input class="form-control" id="new-location-area" name="area" value="{{ old('location_form') ? old('area') : '' }}" maxlength="100" placeholder="e.g. Dhanmondi, Dhaka"></div>
                                <div class="col-md-4"><label class="form-label" for="new-location-phone">Phone</label><input class="form-control @error('phone') is-invalid @enderror" id="new-location-phone" name="phone" type="tel" value="{{ old('location_form') ? old('phone') : '' }}" maxlength="20">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="new-location-directions">Directions</label><input class="form-control" id="new-location-directions" name="directions" value="{{ old('location_form') ? old('directions') : '' }}" maxlength="500" placeholder="e.g. 3rd floor, room 305"></div>
                                <div class="col-12"><button class="btn btn-primary" type="submit">Add location</button></div>
                            </form>
                        </section>
                    </section>

                    <section id="availability-panel" data-schedule-panel="availability" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <div class="doctor-panel__heading">
                                <div><p class="doctor-eyebrow mb-1">Weekly availability</p><h2 class="doctor-panel__title">Manage Availability</h2></div>
                                <span class="badge text-bg-light">10-minute slots</span>
                            </div>
                            <form class="row g-3 align-items-end mb-4" method="POST" action="{{ $workspace->url('doctor.availability.store') }}">
                                @csrf
                                <div class="col-md-3"><label class="form-label" for="doctor_location_id">Location</label><select class="form-select @error('doctor_location_id') is-invalid @enderror" id="doctor_location_id" name="doctor_location_id" required @disabled($activeLocations->isEmpty())>@foreach ($activeLocations as $location)<option value="{{ $location->id }}" @selected((int) old('doctor_location_id') === $location->id)>{{ $location->name }}</option>@endforeach</select>@error('doctor_location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-2"><label class="form-label" for="day_of_week">Day</label><select class="form-select" id="day_of_week" name="day_of_week">@foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day => $name)<option value="{{ $day }}">{{ $name }}</option>@endforeach</select></div>
                                <div class="col-md-2"><label class="form-label" for="start_time">Starts</label><input class="form-control" id="start_time" name="start_time" type="time" value="09:00" required></div>
                                <div class="col-md-2"><label class="form-label" for="end_time">Ends</label><input class="form-control" id="end_time" name="end_time" type="time" value="17:00" required></div>
                                <div class="col-md-3"><button class="btn btn-primary" type="submit" @disabled($activeLocations->isEmpty())>Add availability</button></div>
                                @if ($activeLocations->isEmpty())
                                    <div class="col-12"><p class="small text-secondary mb-0">Add a location in the <a href="#locations-panel" data-open-schedule-tab="locations">Locations</a> tab first.</p></div>
                                @endif
                            </form>
                            @forelse ($profile->availabilities->sortBy('day_of_week') as $availability)
                                <div class="doctor-availability-row">
                                    <strong>{{ ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$availability->day_of_week] }}</strong>
                                    <span>{{ $availability->start_time }} – {{ $availability->end_time }}</span>
                                    <span class="small text-secondary">{{ $availability->location?->name ?? 'Location not specified' }}</span>
                                    <form method="POST" action="{{ $workspace->url('doctor.availability.destroy', $availability) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger" type="submit">Remove</button></form>
                                </div>
                            @empty
                                <p class="text-secondary mb-0">No weekly availability has been configured.</p>
                            @endforelse
                        </section>
                    </section>

                    <section id="waitlist-panel" data-schedule-panel="waitlist" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <div class="doctor-panel__heading">
                                <div><p class="doctor-eyebrow mb-1">Waiting list</p><h2 class="doctor-panel__title">Patients waiting for a time</h2></div>
                                <form method="POST" action="{{ $workspace->url('doctor.waitlist.settings') }}" @unless ($profile->waitlist_enabled) @else data-confirm="Turn the waiting list off? Open offers are withdrawn and their times become bookable again." @endunless>
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="waitlist_enabled" value="{{ $profile->waitlist_enabled ? 0 : 1 }}">
                                    <span class="badge {{ $profile->waitlist_enabled ? 'text-bg-success' : 'text-bg-secondary' }} me-2" data-waitlist-enabled="{{ $profile->waitlist_enabled ? 'on' : 'off' }}">{{ $profile->waitlist_enabled ? 'On' : 'Off' }}</span>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">{{ $profile->waitlist_enabled ? 'Turn off' : 'Turn on' }}</button>
                                </form>
                            </div>
                            <p class="text-secondary">When a time opens, it is held for the first waiting patient who can take it for up to {{ config('booking.waitlist.hold_minutes') }} minutes (ending at least {{ config('booking.waitlist.cutoff_minutes') }} minutes before it starts). You can also offer a time yourself.</p>
                            @forelse ($waitlistEntries as $position => $entry)
                                @php($pending = $entry->pendingOffer)
                                <div class="border rounded-3 p-3 mb-3" data-waitlist-row="{{ $entry->id }}">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <strong>{{ $position + 1 }}. {{ $entry->patient->name }}</strong> <span class="small text-secondary">{{ $entry->patient->email }}</span>
                                            <div class="small text-secondary">{{ $entry->date_from->format('M j') }} – {{ $entry->date_to->format('M j, Y') }} · {{ App\Models\WaitlistEntry::timeOfDayLabel($entry->time_of_day) }} · {{ $entry->location?->name ?? 'Any location' }}@if ($entry->follow_up_of_id) · Follow-up to {{ App\Models\Appointment::referenceFor($entry->follow_up_of_id) }}@endif · joined {{ $entry->created_at->format('M j, g:i A') }}</div>
                                        </div>
                                        <span class="badge {{ $entry->status === 'offered' ? 'text-bg-success' : 'text-bg-light' }}">{{ $entry->statusLabel() }}</span>
                                    </div>
                                    @if ($pending)
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
                                            <span>Held: {{ $pending->slot->starts_at->format('D, M j, g:i A') }} until {{ $pending->expires_at->format('g:i A') }}</span>
                                            <form method="POST" action="{{ $workspace->url('doctor.waitlist.withdraw', $pending) }}" data-confirm="Withdraw this offer? The time goes to the next waiting patient or becomes bookable again.">@csrf<button class="btn btn-sm btn-link text-danger p-0" type="submit">Withdraw offer</button></form>
                                        </div>
                                    @elseif ($entry->status === 'waiting' && $profile->waitlist_enabled && $workspace->routeAvailable('doctor.waitlist.offer'))
                                        @php($matching = $waitlistSlots->filter(fn ($slot) => $entry->wantsSlot($slot))->take(30))
                                        @if ($matching->isNotEmpty())
                                            <form class="d-flex flex-wrap gap-2 mt-2" method="POST" action="{{ $workspace->url('doctor.waitlist.offer', $entry) }}">
                                                @csrf
                                                <label class="visually-hidden" for="waitlist-slot-{{ $entry->id }}">Time to offer {{ $entry->patient->name }}</label>
                                                <select class="form-select form-select-sm w-auto" id="waitlist-slot-{{ $entry->id }}" name="slot_id" required>@foreach ($matching as $slot)<option value="{{ $slot->id }}">{{ $slot->starts_at->format('D, M j, g:i A') }} · {{ $slot->location?->name ?? 'Location not specified' }}</option>@endforeach</select>
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Offer this time</button>
                                            </form>
                                        @else
                                            <p class="small text-secondary mt-2 mb-0">No open time matches this request yet.</p>
                                        @endif
                                    @endif
                                </div>
                            @empty
                                <p class="text-secondary mb-0">Nobody is waiting.{{ $profile->waitlist_enabled ? ' Patients can join from your profile when no time suits them.' : '' }}</p>
                            @endforelse
                        </section>
                    </section>

                    <section id="generate-panel" data-schedule-panel="generate" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <p class="doctor-eyebrow mb-1">Slot generation</p>
                            <h2 class="doctor-panel__title mb-3">Generate 10-minute appointment slots</h2>
                            <form class="row g-3 align-items-end" method="POST" action="{{ $workspace->url('doctor.slots.generate') }}" data-slot-generation-form data-preview-url="{{ $workspace->url('doctor.slots.preview') }}">
                                @csrf
                                <div class="col-md-3"><label class="form-label" for="start_date">Start date</label><input class="form-control" id="start_date" name="start_date" type="date" value="{{ today()->toDateString() }}" required></div>
                                <div class="col-md-3"><label class="form-label" for="end_date">End date</label><input class="form-control" id="end_date" name="end_date" type="date" value="{{ today()->addDays(30)->toDateString() }}" required></div>
                                <div class="col-md-6">
                                    <span class="form-label d-block">Weekdays</span>
                                    @foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekday => $label)
                                        <label class="form-check form-check-inline"><input class="form-check-input" name="weekdays[]" type="checkbox" value="{{ $weekday + 1 }}" checked><span class="form-check-label">{{ substr($label, 0, 3) }}</span></label>
                                    @endforeach
                                </div>
                                <div class="col-md-4"><button class="btn btn-outline-primary" type="button" data-slot-preview>Preview slots</button> <button class="btn btn-primary" type="submit">Generate Slots</button></div>
                            </form>
                            <div class="small text-secondary mt-3" data-slot-preview-output aria-live="polite"></div>
                        </section>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection
