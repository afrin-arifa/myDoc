{{--
    Shown on shared doctor workspace pages that have no sidebar, so a Doctor Assistant always sees whose workspace
    they are using and how to switch. Renders nothing on the doctor's own pages.
--}}
@php($selectedDoctor = $workspace->currentDoctorProfile()?->loadMissing('user:id,name'))

@if ($selectedDoctor)
    <div {{ $attributes->merge(['class' => 'alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 py-2']) }} data-workspace-banner>
        <span class="small">
            <i class="fa-solid fa-user-doctor me-1 text-primary" aria-hidden="true"></i>
            Working for <strong data-selected-doctor>{{ $selectedDoctor->user->name }}</strong>
            <span class="text-secondary">· signed in as {{ auth()->user()->name }} (Doctor Assistant)</span>
        </span>
        <a class="section-link" href="{{ route('assistant.doctors.index') }}">Switch doctor<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
    </div>
@endif
