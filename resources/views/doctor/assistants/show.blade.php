@extends('layouts.app')

@section('title', $assistant->name.' | Assistants | myDoc')

@section('content')
    @php
        $access = App\Services\DoctorAccess::class;
        $abilityGroups = [
            ['icon' => 'fa-regular fa-calendar-check', 'title' => 'Appointments', 'abilities' => [
                $access::VIEW_APPOINTMENTS => 'See your appointments',
                $access::BOOK_APPOINTMENTS => 'Book appointments for patients',
                $access::CONFIRM_APPOINTMENTS => 'Confirm requests',
                $access::REJECT_APPOINTMENTS => 'Reject requests',
                $access::RESCHEDULE_APPOINTMENTS => 'Reschedule appointments',
                $access::CANCEL_APPOINTMENTS => 'Cancel appointments, with a reason',
                $access::START_APPOINTMENTS => 'Start consultations',
                $access::COMPLETE_APPOINTMENTS => 'Mark consultations completed',
            ]],
            ['icon' => 'fa-regular fa-clock', 'title' => 'Schedule', 'abilities' => [
                $access::MANAGE_SCHEDULE => 'Change your weekly hours',
                $access::MANAGE_SLOTS => 'Open, block and generate time slots',
            ]],
            ['icon' => 'fa-regular fa-folder-open', 'title' => 'Patients and records', 'abilities' => [
                $access::VIEW_PATIENTS => 'See your patients',
                $access::REGISTER_PATIENTS => 'Create patient accounts with a one-time password',
                $access::VIEW_MEDICAL_DOCUMENTS => 'See medical documents',
                $access::PREVIEW_MEDICAL_DOCUMENTS => 'Preview documents',
                $access::DOWNLOAD_MEDICAL_DOCUMENTS => 'Download documents',
                $access::UPLOAD_MEDICAL_DOCUMENTS => 'Upload documents',
                $access::DELETE_MEDICAL_DOCUMENTS => 'Delete documents',
            ]],
            ['icon' => 'fa-solid fa-bullhorn', 'title' => 'Announcements', 'abilities' => [
                $access::MANAGE_ANNOUNCEMENTS => 'Post and remove notices for your patients',
            ]],
            ['icon' => 'fa-solid fa-user-gear', 'title' => 'Your account', 'abilities' => [
                $access::EDIT_DOCTOR_PROFILE => 'Edit your profile',
                $access::MANAGE_DOCTOR_ACCOUNT => 'Change your account',
                $access::MANAGE_GOOGLE_CALENDAR => 'Manage Google Calendar',
            ]],
        ];
        $isActive = $assistant->deactivated_at === null;
    @endphp
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container assistant-profile">
        <a class="back-link mb-4" href="{{ route('doctor.assistants.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Your assistants</a>

        <section class="assistant-profile__hero" aria-label="Assistant">
            <div class="assistant-profile__person">
                <x-ui.avatar :name="$assistant->name" :src="$assistant->photoUrl()" size="xl" />
                <div class="min-w-0">
                    <p class="doctor-eyebrow mb-1">Doctor Assistant</p>
                    <h1 class="assistant-profile__name">{{ $assistant->name }}</h1>
                    <span @class(['status-badge', 'status-badge--success' => $isActive, 'status-badge--neutral' => ! $isActive])>{{ $isActive ? 'Active' : 'Deactivated' }}</span>
                </div>
            </div>
            <ul class="assistant-profile__contact">
                @if ($assistant->email)
                    <li><a href="mailto:{{ $assistant->email }}"><i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ $assistant->email }}</a></li>
                @endif
                @if ($assistant->phone)
                    <li><a href="tel:{{ $assistant->phone }}"><i class="fa-solid fa-phone" aria-hidden="true"></i>{{ $assistant->phone }}</a></li>
                @endif
            </ul>
        </section>

        <dl class="appointment-detail__facts">
            <div class="appointment-detail__fact">
                <dt><i class="fa-regular fa-calendar-plus" aria-hidden="true"></i>Working for you since</dt>
                <dd>{{ $assignment->created_at->format('M j, Y') }}</dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-solid fa-list-check" aria-hidden="true"></i>Actions in your workspace</dt>
                <dd>{{ number_format($activityCount) }}</dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-regular fa-clock" aria-hidden="true"></i>Last active</dt>
                <dd>{{ $lastActiveAt ? Carbon\Carbon::parse($lastActiveAt)->setTimezone(config('app.timezone'))->format('M j, Y g:i A') : 'No activity yet' }}</dd>
            </div>
        </dl>

        <div class="row g-4">
            <div class="col-xl-5">
                <section class="doctor-panel h-100" aria-labelledby="access-heading">
                    <div class="doctor-profile-section"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="access-heading">What they can do for you</h2><p>Set by myDoc for every assistant. Ask an administrator about changes.</p></div></div>
                    @foreach ($abilityGroups as $group)
                        <div class="assistant-profile__group">
                            <h3 class="assistant-profile__group-title"><i class="{{ $group['icon'] }}" aria-hidden="true"></i>{{ $group['title'] }}</h3>
                            <ul class="assistant-profile__abilities">
                                @foreach (collect($group['abilities'])->map(fn (string $label, string $ability): array => [$label, in_array($ability, $access::ASSISTANT_ABILITIES, true)]) as [$label, $allowed])
                                    <li @class(['is-allowed' => $allowed, 'is-denied' => ! $allowed])>
                                        <i class="fa-solid {{ $allowed ? 'fa-circle-check' : 'fa-circle-minus' }}" aria-hidden="true"></i>
                                        <span>{{ $label }}<span class="visually-hidden">: {{ $allowed ? 'allowed' : 'not allowed' }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            </div>
            <div class="col-xl-7">
                <section class="doctor-panel h-100" aria-labelledby="recent-heading">
                    <div class="doctor-panel__header">
                        <div class="doctor-profile-section mb-0"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="recent-heading">Recent activity</h2><p>What {{ $assistant->name }} last changed in your workspace.</p></div></div>
                        @if ($activityCount > $recentEvents->count())
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('doctor.assistants.index', ['assistant' => $assistant->getRouteKey()]) }}#activity-heading">View all</a>
                        @endif
                    </div>
                    @if ($recentEvents->isEmpty())
                        <x-ui.empty-state class="py-4" icon="fa-solid fa-clock-rotate-left" title="No activity yet" message="Changes {{ $assistant->name }} makes for you will show here." />
                    @else
                        <ol class="assistant-profile__timeline">
                            @foreach ($recentEvents as $event)
                                <li>
                                    <span class="assistant-profile__dot" aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <p class="assistant-profile__action">{{ $event->actionLabel() }}</p>
                                        <p class="assistant-profile__details"><x-assistant.event-details :event="$event" /></p>
                                    </div>
                                    <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('M j, g:i A') }}</time>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>
        </div>
    </div></div>
@endsection
