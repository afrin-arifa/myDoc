@props(['event'])
{{-- What an assistant's workspace event was about: the appointment, the generated slots, the weekly window or the time. --}}
@if ($event->appointment)
    <a href="{{ route('doctor.appointments.show', $event->appointment) }}">{{ $event->appointment->reference() }}</a>
    · {{ $event->appointment->patient?->name }} · {{ $event->appointment->slot?->starts_at->format('M j, g:i A') }}
@elseif (isset($event->context['medical_document_id']))
    {{ App\Models\MedicalDocument::CATEGORIES[$event->context['document_category'] ?? ''] ?? 'Document' }} for {{ $event->context['patient_name'] ?? 'a patient' }}
@elseif (isset($event->context['announcement_title']))
    “{{ $event->context['announcement_title'] }}”
@elseif (isset($event->context['patient_name']))
    {{ $event->context['patient_name'] }}
@elseif (isset($event->context['slot_count']))
    {{ $event->context['slot_count'] }} {{ Str::plural('slot', $event->context['slot_count']) }}, {{ $event->context['range_start'] ?? '' }} to {{ $event->context['range_end'] ?? '' }}
@elseif (isset($event->context['day_of_week'], $event->context['start_time'], $event->context['end_time']))
    {{ ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$event->context['day_of_week']] ?? '' }} {{ $event->context['start_time'] }}–{{ $event->context['end_time'] }}
@elseif (isset($event->context['starts_at']))
    {{ Carbon\Carbon::parse($event->context['starts_at'])->setTimezone(config('app.timezone'))->format('M j, Y g:i A') }}
@else
    <span class="text-secondary">—</span>
@endif
