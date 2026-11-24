@extends('layouts.app')

@section('title', ($workspace->inAssistantWorkspace() ? 'Schedule for '.$profile->user->name : 'Schedule Timing').' | myDoc')

@section('content')
    @php
        $weekStart = $selectedDate->startOfWeek(Carbon\Carbon::MONDAY);
        $monthStart = $selectedDate->startOfMonth();
        $monthOffset = $monthStart->dayOfWeekIso - 1;
        $calendarStart = $view === 'day' ? $selectedDate : $weekStart;
        $calendarDayCount = $view === 'day' ? 1 : 7;
        [$timelineStartHour, $timelineEndHour] = $timelineHours;
        $timelineMinutes = ($timelineEndHour - $timelineStartHour) * 60;
        $timelinePixelsPerMinute = 2;
        $activeFilters = $activeFilters ?? ['available', 'held', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $previousDate = match ($view) {
            'day' => $selectedDate->subDay(),
            'month' => $selectedDate->subMonthNoOverflow(),
            default => $selectedDate->subWeek(),
        };
        $nextDate = match ($view) {
            'day' => $selectedDate->addDay(),
            'month' => $selectedDate->addMonthNoOverflow(),
            default => $selectedDate->addWeek(),
        };
        $weekdayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $editedAvailabilityId = (int) old('availability_form');
        $now = Carbon\CarbonImmutable::now(config('app.timezone'));
        $statusFilters = [
            'available' => ['Available slot', 'green'],
            'held' => ['Held for waiting list', 'purple'],
            'blocked' => ['Blocked slot', 'gray'],
            'booked' => [App\Models\Appointment::statusLabel('booked'), 'orange'],
            'confirmed' => ['Confirmed', 'blue'],
            'cancelled' => ['Cancelled, rejected or expired', 'red'],
        ];
        $scheduleQuery = array_filter([
            'view' => $view,
            'filters' => array_diff(array_keys($statusFilters), $activeFilters) === [] ? null : implode(',', $activeFilters),
        ]);
        $weekdayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $activeWindows = $profile->availabilities->where('is_active', true)->sortBy('start_time')->groupBy(fn ($window) => $window->day_of_week === 0 ? 7 : $window->day_of_week);
        $selectedWeekdays = array_map('intval', old('start_date') !== null ? (array) old('weekdays', []) : array_keys($weekdayNames));
        $maxRangeDays = App\Http\Requests\Doctor\GenerateDoctorSlotsRequest::MAX_RANGE_DAYS;
    @endphp

    <div class="doctor-workspace doctor-schedule-page">
        <div class="container-fluid doctor-workspace__container">
            <div class="row g-4">

                <div class="col-12">
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
                        </div>
                    </header>

                    @php
                        $activeLocations = $profile->locations->where('is_active', true)->values();
                    @endphp
                    <nav class="doctor-schedule-tabs" aria-label="Schedule sections" data-initial-schedule-tab="{{ old('location_form') ? 'locations' : ($errors->hasAny(['doctor_location_id', 'day_of_week', 'start_time', 'end_time']) ? 'availability' : ($errors->hasAny(['start_date', 'end_date', 'days', 'weekdays', 'weekdays.*']) ? 'generate' : '')) }}">
                        <a class="is-active" href="#schedule-panel" data-schedule-tab="schedule"><i class="fa-regular fa-calendar me-2"></i>Schedule</a>
                        <a href="#locations-panel" data-schedule-tab="locations"><i class="fa-solid fa-location-dot me-2"></i>Locations</a>
                        <a href="#availability-panel" data-schedule-tab="availability"><i class="fa-regular fa-clock me-2"></i>Availability</a>
                        <a href="#generate-panel" data-schedule-tab="generate"><i class="fa-solid fa-layer-group me-2"></i>Generate Slots</a>
                        <a href="#waitlist-panel" data-schedule-tab="waitlist"><i class="fa-solid fa-user-clock me-2"></i>Waiting list @if ($waitlistEntries->isNotEmpty())<span class="badge text-bg-light">{{ $waitlistEntries->count() }}</span>@endif</a>
                    </nav>

                    <section id="schedule-panel" data-schedule-panel="schedule" class="doctor-schedule-tab-panel">
                        <div class="doctor-day-summary">
                            <div class="doctor-day-summary__date">
                                <strong>{{ $selectedDate->isToday() ? 'Today' : $selectedDate->format('l') }}</strong>
                                <small>{{ $selectedDate->format('M j, Y') }}</small>
                            </div>
                            <dl class="doctor-day-summary__facts">
                                @foreach ($summary as $label => $value)
                                    <div class="doctor-day-summary__item doctor-day-summary__item--{{ Str::slug($label) }}"><dt>{{ ucfirst($label) }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                        <div class="doctor-schedule-workspace">
                                                        <section class="doctor-panel doctor-calendar-panel">
                                <div class="doctor-calendar-toolbar">
                                    <div class="doctor-calendar-toolbar__controls">
                                        <a class="doctor-square-button" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $previousDate->toDateString()]) }}" aria-label="Previous {{ $view }}">&lsaquo;</a>
                                        <a class="doctor-square-button" href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $nextDate->toDateString()]) }}" aria-label="Next {{ $view }}">&rsaquo;</a>
                                        <a class="btn btn-sm btn-outline-primary" href="{{ $workspace->url('doctor.schedule', ['view' => $view]) }}">Today</a>
                                    </div>
                                    <strong aria-live="polite">
                                        @if ($view === 'day')
                                            {{ $selectedDate->format('D, M j, Y') }}
                                        @elseif ($view === 'month')
                                            {{ $selectedDate->format('F Y') }}
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

                                @if ($view !== 'month')
                                    <div class="doctor-filter-list" role="group" aria-label="Show on calendar">
                                    @foreach ($statusFilters as $filter => [$label, $color])
                                        <label class="doctor-filter-option">
                                            <input class="schedule-filter" type="checkbox" value="{{ $filter }}" {{ in_array($filter, $activeFilters, true) ? 'checked' : '' }}>
                                            <span class="doctor-filter-swatch doctor-filter-swatch--{{ $color }}" aria-hidden="true"></span>
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                    <label class="doctor-filter-option">
                                        <input class="schedule-consultation-filter" type="checkbox" checked>
                                        <span class="doctor-filter-swatch doctor-filter-swatch--teal" aria-hidden="true"></span>
                                        <span>Consultations</span>
                                    </label>
                                </div>
                                @endif

                                @if ($slots->isEmpty())
                                    <div class="doctor-calendar-empty">
                                        <i class="fa-regular fa-calendar-plus" aria-hidden="true"></i>
                                        <div><strong>No slots for this {{ $view }}</strong><span>Generate slots from your weekly availability so patients can book.</span></div>
                                        <a class="btn btn-sm btn-primary" href="#generate-panel" data-open-schedule-tab="generate">Generate Slots</a>
                                    </div>
                                @endif

                                @if ($view === 'month')
                                    <div class="doctor-month-calendar">
                                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                                            <strong>{{ $dayName }}</strong>
                                        @endforeach
                                        @foreach (range(0, (int) $monthStart->startOfWeek(Carbon\Carbon::MONDAY)->diffInDays($monthStart->endOfMonth()->endOfWeek(Carbon\Carbon::SUNDAY)->startOfDay())) as $monthDayOffset)
                                            @php
                                                $monthDay = $monthStart->startOfWeek(Carbon\Carbon::MONDAY)->addDays($monthDayOffset);
                                                $monthDaySlots = $slotsByDate[$monthDay->toDateString()] ?? collect();
                                            @endphp
                                            <a class="{{ $monthDay->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $monthDay->month !== $selectedDate->month ? 'is-muted' : '' }} {{ $monthDay->isToday() ? 'is-today' : '' }}" data-calendar-date="{{ $monthDay->toDateString() }}" href="{{ $workspace->url('doctor.schedule', ['date' => $monthDay->toDateString(), 'view' => 'day']) }}">
                                                <strong>{{ $monthDay->day }}</strong>
                                                @php
                                                    $monthCounts = [
                                                        'booked' => $monthDaySlots->where('status', 'booked')->count(),
                                                        'available' => $monthDaySlots->where('status', 'available')->count(),
                                                        'held' => $monthDaySlots->where('status', 'held')->count(),
                                                        'blocked' => $monthDaySlots->where('status', 'blocked')->count(),
                                                    ];
                                                @endphp
                                                @foreach (array_filter($monthCounts) as $slotStatus => $count)
                                                    <span class="doctor-month-calendar__count doctor-month-calendar__count--{{ $slotStatus }}">{{ $count }} <span>{{ $slotStatus === 'available' ? 'open' : $slotStatus }}</span></span>
                                                @endforeach
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                <div class="doctor-calendar-grid" data-calendar-scroll data-timeline="{{ $timelineStartHour }}-{{ $timelineEndHour }}" style="--hour-height: {{ 60 * $timelinePixelsPerMinute }}px; --timeline-height: {{ $timelineMinutes * $timelinePixelsPerMinute }}px;">
                                    <div class="doctor-calendar-grid__times">
                                        <span></span>
                                        @foreach (range($timelineStartHour, $timelineEndHour) as $hour)
                                            <span>{{ Carbon\Carbon::createFromTime($hour % 24)->format('g:i A') }}</span>
                                        @endforeach
                                    </div>
                                    <div class="doctor-calendar-grid__days">
                                        @foreach (range(0, $calendarDayCount - 1) as $dayOffset)
                                            @php
                                                $day = $calendarStart->addDays($dayOffset);
                                                $dayEvents = $calendarEventsByDate[$day->toDateString()] ?? collect();
                                            @endphp
                                            <div class="doctor-calendar-day {{ $day->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $day->isToday() ? 'is-today' : '' }}">
                                                <header>
                                                    <span>{{ $day->format('D') }}</span>
                                                    <a href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $day->toDateString(), 'view' => 'day']) }}" aria-label="{{ $day->format('l, F j') }}"><strong>{{ $day->format('j') }}</strong></a>
                                                </header>
                                                <div class="doctor-calendar-day__body">
                                                    <div class="doctor-calendar-day__lines"></div>
                                                    @if ($day->isToday() && $now->hour >= $timelineStartHour && $now->hour < $timelineEndHour)
                                                        <div class="doctor-calendar-now" style="top: {{ (($now->hour - $timelineStartHour) * 60 + $now->minute) * $timelinePixelsPerMinute }}px;" aria-hidden="true"></div>
                                                    @endif
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
                                                                    <span class="doctor-calendar-event__time">{{ $eventStart->format('g:i') }}</span>
<small>{{ $eventTime }}</small>
<div class="doctor-calendar-event__actions">
                                                                    @if ($event['appointment_id'])
                                                                        <a class="doctor-calendar-event__link" href="{{ $workspace->url('doctor.appointments.show', App\Models\Appointment::urlKey($event['appointment_id'])) }}" aria-label="View appointment details">View</a>
                                                                        @if ($event['can_confirm'])
                                                                            <form method="POST" action="{{ $workspace->url('doctor.appointments.confirm', App\Models\Appointment::urlKey($event['appointment_id'])) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Confirm appointment">Confirm</button>
                                                                            </form>
                                                                            <form method="POST" action="{{ $workspace->url('doctor.appointments.reject', App\Models\Appointment::urlKey($event['appointment_id'])) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action doctor-calendar-event__action--danger" type="submit" aria-label="Reject appointment">Reject</button>
                                                                            </form>
                                                                        @elseif ($event['can_start'] && $workspace->routeAvailable('doctor.appointments.start'))
                                                                            <form method="POST" action="{{ route('doctor.appointments.start', App\Models\Appointment::urlKey($event['appointment_id'])) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Start consultation">Start</button>
                                                                            </form>
                                                                        @endif
                                                                    @else
                                                                        @if ($event['can_book'] ?? false)
                                                                            <a class="doctor-calendar-event__link" href="{{ $workspace->url('doctor.appointments.create', ['date' => $event['date'], 'slot' => App\Models\AppointmentSlot::urlKey($event['slot_id'])]) }}" aria-label="Book this available slot">Book</a>
                                                                        @endif
                                                                        @if ($event['can_block'] ?? false)
                                                                            <form method="POST" action="{{ $workspace->url('doctor.slots.block', App\Models\AppointmentSlot::urlKey($event['slot_id'])) }}" data-confirm="Block this slot? Patients will not be able to book it.">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Block this slot">Block</button>
                                                                            </form>
                                                                        @elseif ($event['can_unblock'] ?? false)
                                                                            <form method="POST" action="{{ $workspace->url('doctor.slots.unblock', App\Models\AppointmentSlot::urlKey($event['slot_id'])) }}">
                                                                                @csrf @method('DELETE')
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Unblock this slot">Unblock</button>
                                                                            </form>
                                                                        @endif
                                                                    @endif
</div>
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
                                <section class="doctor-panel" aria-label="Choose a date"><div class="doctor-mini-calendar">
                                    <div class="doctor-mini-calendar__header">
                                        <a href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->subMonthNoOverflow()->toDateString()]) }}" aria-label="Previous month">&lsaquo;</a>
                                        <strong>{{ $selectedDate->format('F Y') }}</strong>
                                        <a href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->addMonthNoOverflow()->toDateString()]) }}" aria-label="Next month">&rsaquo;</a>
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
                                            <a class="{{ $miniDate->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $miniDate->isToday() ? 'is-today' : '' }}" aria-label="{{ $miniDate->format('F j, Y') }}" @if ($miniDate->isSameDay($selectedDate)) aria-current="date" @endif href="{{ $workspace->url('doctor.schedule', [...$scheduleQuery, 'date' => $miniDate->toDateString()]) }}">{{ $day }}</a>
                                        @endfor
                                    </div>
                                </div></section>
                                <section class="doctor-panel">
                                    <div class="doctor-panel__heading">
                                        <h2 class="doctor-panel__title">{{ $selectedDate->isToday() ? 'Today’s Appointments' : 'Appointments on '.$selectedDate->format('M j') }}</h2>
                                        <a class="section-link" href="{{ $workspace->url('doctor.appointments.index') }}">View All<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
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
                                <section class="doctor-panel">
                                    <h2 class="doctor-panel__title mb-3">Quick Actions</h2>
                                    <a class="doctor-quick-action" href="{{ $workspace->url('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}"><i class="fa-solid fa-plus"></i><span>New Appointment</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#generate-panel" data-open-schedule-tab="generate"><i class="fa-solid fa-layer-group"></i><span>Generate Slots</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#availability-panel" data-open-schedule-tab="availability"><i class="fa-regular fa-clock"></i><span>Manage Availability</span><b>&rsaquo;</b></a>
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
                                <div class="doctor-list-card" data-location-row="{{ $location->id }}">
                                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                        <div>
                                            <strong>{{ $location->name }}</strong>
                                            @unless ($location->is_active)<span class="badge text-bg-secondary ms-1">Archived</span>@endunless
                                            <div class="small text-secondary">{{ $location->address ?? 'Address not provided yet' }}{{ $location->area ? ', '.$location->area : '' }}{{ $location->phone ? ' · '.$location->phone : '' }}</div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 align-items-start">
                                            <form method="POST" action="{{ $workspace->url('doctor.locations.status', $location) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $location->is_active ? 0 : 1 }}"><button class="btn btn-sm btn-outline-secondary" type="submit">{{ $location->is_active ? 'Archive' : 'Restore' }}</button></form>
                                            <form method="POST" action="{{ $workspace->url('doctor.locations.destroy', $location) }}" data-confirm="Delete {{ $location->name }}? Only locations that were never used can be deleted.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
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
                                <input type="hidden" name="is_active" value="1">
                                <div class="col-md-3"><label class="form-label" for="doctor_location_id">Location</label><select class="form-select {{ ! $editedAvailabilityId && $errors->has('doctor_location_id') ? 'is-invalid' : '' }}" id="doctor_location_id" name="doctor_location_id" required @disabled($activeLocations->isEmpty())>@foreach ($activeLocations as $location)<option value="{{ $location->id }}" @selected(! $editedAvailabilityId && (int) old('doctor_location_id') === $location->id)>{{ $location->name }}</option>@endforeach</select>@if (! $editedAvailabilityId && $errors->has('doctor_location_id'))<div class="invalid-feedback">{{ $errors->first('doctor_location_id') }}</div>@endif</div>
                                <div class="col-md-2"><label class="form-label" for="day_of_week">Day</label><select class="form-select" id="day_of_week" name="day_of_week">@foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day => $name)<option value="{{ $day }}" @selected(! $editedAvailabilityId && (int) old('day_of_week') === $day)>{{ $name }}</option>@endforeach</select></div>
                                <div class="col-md-2"><label class="form-label" for="start_time">Starts</label><input class="form-control {{ ! $editedAvailabilityId && $errors->has('start_time') ? 'is-invalid' : '' }}" id="start_time" name="start_time" type="time" value="{{ $editedAvailabilityId ? '09:00' : old('start_time', '09:00') }}" required>@if (! $editedAvailabilityId && $errors->has('start_time'))<div class="invalid-feedback">{{ $errors->first('start_time') }}</div>@endif</div>
                                <div class="col-md-2"><label class="form-label" for="end_time">Ends</label><input class="form-control {{ ! $editedAvailabilityId && $errors->has('end_time') ? 'is-invalid' : '' }}" id="end_time" name="end_time" type="time" value="{{ $editedAvailabilityId ? '17:00' : old('end_time', '17:00') }}" required aria-describedby="end_time_help">@if (! $editedAvailabilityId && $errors->has('end_time'))<div class="invalid-feedback">{{ $errors->first('end_time') }}</div>@endif</div>
                                <div class="col-md-3"><button class="btn btn-primary" type="submit" @disabled($activeLocations->isEmpty())>Add availability</button></div>
                                <div class="col-12"><p class="form-text mt-0 mb-0" id="end_time_help">Choose any hours, including evenings (for example 5:00 PM to 11:00 PM). You can add several windows on the same day.</p></div>
                                @if ($activeLocations->isEmpty())
                                    <div class="col-12"><p class="small text-secondary mb-0">Add a location in the <a href="#locations-panel" data-open-schedule-tab="locations">Locations</a> tab first.</p></div>
                                @endif
                            </form>
                            @forelse ($profile->availabilities->sortBy(['day_of_week', 'start_time']) as $availability)
                                <div class="doctor-availability-item" data-availability-row="{{ $availability->id }}">
                                    <div class="doctor-availability-row">
                                        <strong>{{ $weekdayLabels[$availability->day_of_week] }}</strong>
                                        <span>{{ Carbon\Carbon::parse($availability->start_time)->format('g:i A') }} – {{ Carbon\Carbon::parse($availability->end_time)->format('g:i A') }}</span>
                                        <span class="small text-secondary">{{ $availability->location?->name ?? 'Location not specified' }}</span>
                                        <span><span class="status-badge status-badge--{{ $availability->is_active ? 'success' : 'neutral' }}">{{ $availability->is_active ? 'Active' : 'Paused' }}</span></span>
                                        <div class="doctor-row-actions">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-availability-{{ $availability->id }}" aria-expanded="{{ $editedAvailabilityId === $availability->id ? 'true' : 'false' }}" aria-controls="edit-availability-{{ $availability->id }}">Edit</button>
                                            <form method="POST" action="{{ $workspace->url('doctor.availability.update', $availability) }}" @if ($availability->is_active) data-confirm="Pause these hours? Open future slots in them are removed; existing appointments are unchanged." @endif>
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="day_of_week" value="{{ $availability->day_of_week }}">
                                                <input type="hidden" name="start_time" value="{{ substr($availability->start_time, 0, 5) }}">
                                                <input type="hidden" name="end_time" value="{{ substr($availability->end_time, 0, 5) }}">
                                                <input type="hidden" name="is_active" value="{{ $availability->is_active ? 0 : 1 }}">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit">{{ $availability->is_active ? 'Pause' : 'Turn on' }}</button>
                                            </form>
                                            <form method="POST" action="{{ $workspace->url('doctor.availability.destroy', $availability) }}" data-confirm="Remove these hours? Open future slots in them are removed; existing appointments are unchanged.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form>
                                        </div>
                                    </div>
                                    <div @class(['collapse', 'show' => $editedAvailabilityId === $availability->id]) id="edit-availability-{{ $availability->id }}">
                                        <form class="doctor-availability-edit row g-3 align-items-end mx-0" method="POST" action="{{ $workspace->url('doctor.availability.update', $availability) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="availability_form" value="{{ $availability->id }}">
                                            <input type="hidden" name="is_active" value="{{ $availability->is_active ? 1 : 0 }}">
                                            @php
                                                $isEdited = $editedAvailabilityId === $availability->id;
                                                $editLocationId = (int) ($isEdited ? old('doctor_location_id') : $availability->doctor_location_id);
                                                $editDay = (int) ($isEdited ? old('day_of_week') : $availability->day_of_week);
                                                $editTimes = ['start_time' => 'Starts', 'end_time' => 'Ends'];
                                            @endphp
                                            <div class="col-md-3"><label class="form-label" for="edit-location-{{ $availability->id }}">Location</label><select class="form-select {{ $isEdited && $errors->has('doctor_location_id') ? 'is-invalid' : '' }}" id="edit-location-{{ $availability->id }}" name="doctor_location_id" required>@unless ($activeLocations->contains('id', $editLocationId))<option value="" selected disabled>Choose a location</option>@endunless @foreach ($activeLocations as $location)<option value="{{ $location->id }}" @selected($editLocationId === $location->id)>{{ $location->name }}</option>@endforeach</select>@if ($isEdited && $errors->has('doctor_location_id'))<div class="invalid-feedback">{{ $errors->first('doctor_location_id') }}</div>@endif</div>
                                            <div class="col-md-2"><label class="form-label" for="edit-day-{{ $availability->id }}">Day</label><select class="form-select" id="edit-day-{{ $availability->id }}" name="day_of_week">@foreach ($weekdayLabels as $day => $name)<option value="{{ $day }}" @selected($editDay === $day)>{{ $name }}</option>@endforeach</select></div>
                                            @foreach ($editTimes as $field => $label)
                                                <div class="col-md-2"><label class="form-label" for="edit-{{ $field }}-{{ $availability->id }}">{{ $label }}</label><input class="form-control {{ $isEdited && $errors->has($field) ? 'is-invalid' : '' }}" id="edit-{{ $field }}-{{ $availability->id }}" name="{{ $field }}" type="time" value="{{ $isEdited ? old($field) : substr($availability->$field, 0, 5) }}" required>@if ($isEdited && $errors->has($field))<div class="invalid-feedback">{{ $errors->first($field) }}</div>@endif</div>
                                            @endforeach
                                            <div class="col-md-3"><div class="doctor-row-actions justify-content-start"><button class="btn btn-primary" type="submit">Save</button><button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-availability-{{ $availability->id }}">Cancel</button></div></div>
                                            <div class="col-12"><p class="form-text m-0">Open future slots outside the new hours are removed, and existing appointments are unchanged. Generate slots again for any added hours.</p></div>
                                        </form>
                                    </div>
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
                                <div class="doctor-list-card" data-waitlist-row="{{ $entry->id }}">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <strong>{{ $position + 1 }}. {{ $entry->patient->name }}</strong> <span class="small text-secondary">{{ $entry->patient->contactLabel() }}</span>
                                            <div class="small text-secondary">{{ $entry->date_from->format('M j') }} – {{ $entry->date_to->format('M j, Y') }} · {{ App\Models\WaitlistEntry::timeOfDayLabel($entry->time_of_day) }} · {{ $entry->location?->name ?? 'Any location' }}@if ($entry->follow_up_of_id) · Follow-up to {{ App\Models\Appointment::referenceFor($entry->follow_up_of_id) }}@endif · joined {{ $entry->created_at->format('M j, g:i A') }}</div>
                                        </div>
                                        <span class="badge {{ $entry->status === 'offered' ? 'text-bg-success' : 'text-bg-light' }}">{{ $entry->statusLabel() }}</span>
                                    </div>
                                    @if ($pending)
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
                                            <span>Held: {{ $pending->slot->starts_at->format('D, M j, g:i A') }} until {{ $pending->expires_at->format('g:i A') }}</span>
                                            <form method="POST" action="{{ $workspace->url('doctor.waitlist.withdraw', $pending) }}" data-confirm="Withdraw this offer? The time goes to the next waiting patient or becomes bookable again.">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">Withdraw offer</button></form>
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
                        <div class="row g-4">
                            <div class="col-xl-8">
                                <section class="doctor-panel">
                                    <div class="doctor-profile-section"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><div><h2 class="doctor-panel__title">Generate 10-minute appointment slots</h2><p>Opens the weekly availability for booking on the dates you choose. Existing slots are never changed.</p></div></div>
                                    @if ($activeWindows->isEmpty())
                                        <x-ui.alert type="warning" class="mb-4">
                                            @if ($profile->availabilities->isNotEmpty())
                                                <p class="fw-semibold mb-1">Your weekly availability is paused</p>
                                                <p class="mb-0">Turn on the hours you want patients to book. <a href="#availability-panel" data-open-schedule-tab="availability">Open availability</a></p>
                                            @else
                                                <p class="fw-semibold mb-1">Add weekly availability first</p>
                                                <p class="mb-0">Slots are created from weekly hours, and none are set yet. <a href="#availability-panel" data-open-schedule-tab="availability">Add availability</a></p>
                                            @endif
                                        </x-ui.alert>
                                    @endif
                                    <form method="POST" action="{{ $workspace->url('doctor.slots.generate') }}" data-slot-generation-form data-preview-url="{{ $workspace->url('doctor.slots.preview') }}">
                                        @csrf
                                        <div class="row g-3">
                                            <div class="col-sm-6"><label class="form-label" for="start_date">Start date</label><input class="form-control @error('start_date') is-invalid @enderror" id="start_date" name="start_date" type="date" value="{{ old('start_date', today()->toDateString()) }}" min="{{ today()->toDateString() }}" required>@error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                            <div class="col-sm-6"><label class="form-label" for="end_date">End date</label><input class="form-control @error('end_date') is-invalid @enderror" id="end_date" name="end_date" type="date" value="{{ old('end_date', today()->addDays(30)->toDateString()) }}" min="{{ today()->toDateString() }}" aria-describedby="end_date_help" required>@error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text" id="end_date_help">Up to {{ $maxRangeDays }} days at a time.</div></div>
                                            <div class="col-12">
                                                <span class="form-label d-block" id="generate-weekdays-label">Weekdays</span>
                                                <div class="doctor-specialty-picker" role="group" aria-labelledby="generate-weekdays-label">
                                                    @foreach ($weekdayNames as $weekday => $label)
                                                        <label class="doctor-specialty-option"><input class="form-check-input" name="weekdays[]" type="checkbox" value="{{ $weekday }}" @checked(in_array($weekday, $selectedWeekdays, true))><span>{{ substr($label, 0, 3) }}</span></label>
                                                    @endforeach
                                                </div>
                                                @error('weekdays')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-outline-primary" type="button" data-slot-preview>Preview slots</button><button class="btn btn-primary" type="submit" @disabled($activeWindows->isEmpty())>Generate Slots</button></div>
                                    </form>
                                    <div class="doctor-slot-preview" data-slot-preview-output aria-live="polite"></div>
                                </section>
                            </div>
                            <div class="col-xl-4">
                                <section class="doctor-panel">
                                    <div class="doctor-panel__heading mb-2"><h2 class="doctor-panel__title">Weekly availability</h2><a class="section-link" href="#availability-panel" data-open-schedule-tab="availability">Edit<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
                                    <dl class="doctor-weekly-hours">
                                        @foreach ($weekdayNames as $weekday => $label)
                                            <div @class(['is-closed' => ! $activeWindows->has($weekday)])><dt>{{ $label }}</dt><dd>
                                                @forelse ($activeWindows->get($weekday, []) as $window)
                                                    <span>{{ Carbon\Carbon::parse($window->start_time)->format('g:i A') }} – {{ Carbon\Carbon::parse($window->end_time)->format('g:i A') }}</span>
                                                @empty
                                                    <span>No hours</span>
                                                @endforelse
                                            </dd></div>
                                        @endforeach
                                    </dl>
                                </section>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection
