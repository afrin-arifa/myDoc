{{-- Cards for the doctors an assistant currently works for. Expects $doctors with pending_count and upcoming_count. --}}
<div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
    @foreach ($doctors as $doctor)
        <div class="col">
            <article class="card border-0 shadow-sm h-100 p-4" aria-labelledby="doctor-{{ $doctor->id }}-name">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img class="rounded-circle object-fit-cover" src="{{ $doctor->photoUrl() ?? asset('images/doctor.png') }}" alt="" width="56" height="56">
                    <div class="min-w-0">
                        <h3 class="h6 mb-0 text-truncate" id="doctor-{{ $doctor->id }}-name">{{ $doctor->user->name }}</h3>
                        <p class="small text-secondary mb-0 text-truncate">{{ $doctor->professional_title }}</p>
                    </div>
                </div>
                <dl class="row small mb-3">
                    <dt class="col-8 text-secondary fw-normal">Awaiting confirmation</dt><dd class="col-4 text-end mb-1" data-pending-count>{{ $doctor->pending_count }}</dd>
                    <dt class="col-8 text-secondary fw-normal">Upcoming appointments</dt><dd class="col-4 text-end mb-0" data-upcoming-count>{{ $doctor->upcoming_count }}</dd>
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-auto">
                    <a class="btn btn-sm btn-primary" href="{{ route('assistant.workspace.appointments.index', $doctor) }}">Open workspace<span class="visually-hidden"> for {{ $doctor->user->name }}</span></a>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('assistant.workspace.schedule', $doctor) }}">Schedule</a>
                </div>
            </article>
        </div>
    @endforeach
</div>
