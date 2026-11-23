@props(['appointment', 'audience' => 'patient'])
{{--
    The follow-up relationship of an appointment: the original it follows up, and the follow-ups booked from it.
    Links go to the page this audience may open (patient, doctor/assistant workspace, or admin). Only references,
    dates and statuses are shown.
--}}
@php
    $urlFor = fn (int $id): string => match ($audience) {
        'admin' => route('admin.appointments.show', $id),
        'staff' => $workspace->url('doctor.appointments.show', $id),
        default => route('patient.appointments.show', $id),
    };
    $original = $appointment->followUpOf;
    $followUps = $appointment->followUps->sortBy('id');
@endphp
@if ($original || $followUps->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'small']) }} data-follow-up-links>
        @if ($original)
            <p class="mb-1"><i class="fa-solid fa-rotate-left text-secondary me-1" aria-hidden="true"></i>Follow-up to
                <a href="{{ $urlFor($original->id) }}">{{ $original->reference() }}</a>
                ({{ $original->slot?->starts_at?->format('M j, Y') }})</p>
        @endif
        @foreach ($followUps as $followUp)
            <p class="mb-1"><i class="fa-solid fa-rotate-right text-secondary me-1" aria-hidden="true"></i>Follow-up:
                <a href="{{ $urlFor($followUp->id) }}">{{ $followUp->reference() }}</a>
                ({{ $followUp->slot?->starts_at?->format('M j, Y') }}, {{ App\Models\Appointment::statusLabel($followUp->status) }})</p>
        @endforeach
    </div>
@endif
