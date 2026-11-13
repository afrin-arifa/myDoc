@extends('layouts.app')

@section('title', 'Schedule Timing | myDoc')

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
        $activeFilters = $activeFilters ?? ['available', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $scheduleQuery = ['view' => $view, 'filters' => $activeFilters];
        $statusFilters = [
            'available' => ['Available slot', 'green'],
            'blocked' => ['Blocked slot', 'gray'],
            'booked' => ['Booked / Pending', 'orange'],
            'confirmed' => ['Confirmed', 'blue'],
            'cancelled' => ['Cancelled / Rejected', 'red'],
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
                            <p class="doctor-eyebrow"><i class="fa-regular fa-calendar me-2"></i>Doctor workspace</p>
                            <h1 class="doctor-page-title">My Schedule</h1>
                            <p class="text-secondary mb-0">Manage your availability, appointments, and consultation slots.</p>
                        </div>
                        <div class="doctor-schedule-header__actions">
                            <a class="btn btn-primary" href="{{ route('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}">
                                <i class="fa-solid fa-plus me-1"></i> New Appointment
                            </a>
                            <button class="doctor-icon-button" type="button" aria-label="Notifications">
                                <i class="fa-regular fa-bell"></i>
                            </button>
                            <a class="doctor-icon-button" href="{{ route('doctor.settings') }}" aria-label="Account settings">
                                <i class="fa-regular fa-user"></i>
                            </a>
                        </div>
                    </header>

                    <nav class="doctor-schedule-tabs" aria-label="Schedule sections">
                        <a class="is-active" href="#schedule-panel" data-schedule-tab="schedule"><i class="fa-regular fa-calendar me-2"></i>Schedule</a>
                        <a href="#availability-panel" data-schedule-tab="availability"><i class="fa-solid fa-layer-group me-2"></i>Availability</a>
                        <a href="#generate-panel" data-schedule-tab="generate"><i class="fa-solid fa-layer-group me-2"></i>Generate Slots</a>
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
                                        <a href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->subMonth()->toDateString()]) }}" aria-label="Previous month">&lsaquo;</a>
                                        <strong>{{ $selectedDate->format('F Y') }}</strong>
                                        <a href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->addMonth()->toDateString()]) }}" aria-label="Next month">&rsaquo;</a>
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
                                            <a class="{{ $miniDate->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $miniDate->isToday() ? 'is-today' : '' }}" href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $miniDate->toDateString()]) }}">{{ $day }}</a>
                                        @endfor
                                    </div>
                                </div>
                            </aside>

                            <section class="doctor-panel doctor-calendar-panel">
                                <div class="doctor-calendar-toolbar">
                                    <div class="doctor-calendar-toolbar__controls">
                                        <a class="doctor-square-button" href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->subWeek()->toDateString()]) }}" aria-label="Previous week">&lsaquo;</a>
                                        <a class="doctor-square-button" href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->addWeek()->toDateString()]) }}" aria-label="Next week">&rsaquo;</a>
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('doctor.schedule') }}">Today</a>
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
                                            <a class="btn {{ $view === $mode ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('doctor.schedule', [...$scheduleQuery, 'date' => $selectedDate->toDateString(), 'view' => $mode]) }}">{{ $label }}</a>
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
                                            <a class="{{ $monthDay->isSameDay($selectedDate) ? 'is-selected' : '' }} {{ $monthDay->month !== $selectedDate->month ? 'is-muted' : '' }}" data-calendar-date="{{ $monthDay->toDateString() }}" href="{{ route('doctor.schedule', ['date' => $monthDay->toDateString(), 'view' => 'day']) }}">
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
                                                                $eventLabel = $event['patient_name'] ?? match ($status) { 'available' => 'Available', 'blocked' => 'Blocked', default => ucfirst($event['appointment_status']) };
                                                                $eventTime = $eventStart->format('g:i A').' - '.$eventEnd->format('g:i A');
                                                        @endphp
                                                        <article
                                                                class="doctor-calendar-event doctor-calendar-event--{{ $status }}"
                                                                data-schedule-status="{{ $status }}"
                                                                    data-schedule-kind="{{ $event['type'] === 'appointment' ? 'consultation' : 'available' }}"
                                                                    data-event-type="{{ $event['type'] }}"
                                                                    data-event-date="{{ $event['date'] }}"
                                                                    data-appointment-status="{{ $event['appointment_status'] }}"
                                                                    title="{{ $eventLabel }} ({{ $eventTime }})"
                                                                    style="--event-top: {{ $eventTop }}px; --event-height: {{ $eventHeight }}px;"
                                                                >
                                                                    <span class="doctor-calendar-event__indicator" aria-hidden="true"></span>
                                                                    <strong>{{ $eventLabel }}</strong>
                                                                    <small>{{ $eventTime }}</small>
                                                                    @if ($event['appointment_id'])
                                                                        <a class="doctor-calendar-event__link" href="{{ route('doctor.appointments.show', $event['appointment_id']) }}" aria-label="View appointment details">View</a>
                                                                        @if ($event['can_confirm'])
                                                                            <form method="POST" action="{{ route('doctor.appointments.confirm', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Confirm appointment">Confirm</button>
                                                                            </form>
                                                                            <form method="POST" action="{{ route('doctor.appointments.reject', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action doctor-calendar-event__action--danger" type="submit" aria-label="Reject appointment">Reject</button>
                                                                            </form>
                                                                        @elseif ($event['can_start'])
                                                                            <form method="POST" action="{{ route('doctor.appointments.start', $event['appointment_id']) }}">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Start consultation">Start</button>
                                                                            </form>
                                                                        @endif
                                                                    @else
                                                                        @if ($event['type'] === 'available')
                                                                            <a class="doctor-calendar-event__link" href="{{ route('doctor.appointments.create', ['date' => $event['date'], 'slot_id' => $event['slot_id']]) }}" aria-label="Book this available slot">Book</a>
                                                                        @endif
                                                                        @if ($event['can_block'] ?? false)
                                                                            <form method="POST" action="{{ route('doctor.slots.block', $event['slot_id']) }}" data-confirm="Block this slot? Patients will not be able to book it.">
                                                                                @csrf
                                                                                <button class="doctor-calendar-event__action" type="submit" aria-label="Block this slot">Block</button>
                                                                            </form>
                                                                        @elseif ($event['can_unblock'] ?? false)
                                                                            <form method="POST" action="{{ route('doctor.slots.unblock', $event['slot_id']) }}">
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
                                    <a class="doctor-quick-action" href="{{ route('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}"><i class="fa-solid fa-plus"></i><span>New Appointment</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#generate-panel" data-open-schedule-tab="generate"><i class="fa-solid fa-layer-group"></i><span>Generate Slots</span><b>&rsaquo;</b></a>
                                    <a class="doctor-quick-action" href="#availability-panel" data-open-schedule-tab="availability"><i class="fa-regular fa-calendar"></i><span>Manage Availability</span><b>&rsaquo;</b></a>
                                </section>

                                <section class="doctor-panel">
                                    <div class="doctor-panel__heading">
                                        <h2 class="doctor-panel__title">Today’s Appointments</h2>
                                        <a href="{{ route('doctor.appointments.index') }}">View All &rsaquo;</a>
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

                    <section id="availability-panel" data-schedule-panel="availability" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <div class="doctor-panel__heading">
                                <div><p class="doctor-eyebrow mb-1">Weekly availability</p><h2 class="doctor-panel__title">Manage Availability</h2></div>
                                <span class="badge text-bg-light">10-minute slots</span>
                            </div>
                            <form class="row g-3 align-items-end mb-4" method="POST" action="{{ route('doctor.availability.store') }}">
                                @csrf
                                <div class="col-md-3"><label class="form-label" for="day_of_week">Day</label><select class="form-select" id="day_of_week" name="day_of_week">@foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day => $name)<option value="{{ $day }}">{{ $name }}</option>@endforeach</select></div>
                                <div class="col-md-3"><label class="form-label" for="start_time">Starts</label><input class="form-control" id="start_time" name="start_time" type="time" value="09:00" required></div>
                                <div class="col-md-3"><label class="form-label" for="end_time">Ends</label><input class="form-control" id="end_time" name="end_time" type="time" value="17:00" required></div>
                                <div class="col-md-3"><button class="btn btn-primary" type="submit">Add availability</button></div>
                            </form>
                            @forelse ($profile->availabilities->sortBy('day_of_week') as $availability)
                                <div class="doctor-availability-row">
                                    <strong>{{ ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$availability->day_of_week] }}</strong>
                                    <span>{{ $availability->start_time }} – {{ $availability->end_time }}</span>
                                    <form method="POST" action="{{ route('doctor.availability.destroy', $availability) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger" type="submit">Remove</button></form>
                                </div>
                            @empty
                                <p class="text-secondary mb-0">No weekly availability has been configured.</p>
                            @endforelse
                        </section>
                    </section>

                    <section id="generate-panel" data-schedule-panel="generate" class="doctor-schedule-tab-panel" hidden>
                        <section class="doctor-panel">
                            <p class="doctor-eyebrow mb-1">Slot generation</p>
                            <h2 class="doctor-panel__title mb-3">Generate 10-minute appointment slots</h2>
                            <form class="row g-3 align-items-end" method="POST" action="{{ route('doctor.slots.generate') }}" data-slot-generation-form data-preview-url="{{ route('doctor.slots.preview') }}">
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
