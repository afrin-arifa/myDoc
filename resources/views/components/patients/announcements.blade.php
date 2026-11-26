@props(['announcements'])
{{-- Notices from the patient's doctors on the patient dashboard. Each can be dismissed. --}}
@if ($announcements->isNotEmpty())
    <section class="patient-notices mb-4" aria-labelledby="notices-heading">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0" id="notices-heading"><i class="fa-solid fa-bullhorn text-primary me-2" aria-hidden="true"></i>Notices from your doctors</h2>
            <a class="section-link" href="{{ route('patient.announcements.index') }}">View all notices<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        @foreach ($announcements as $announcement)
            <article @class(['patient-notice', 'is-important' => $announcement->is_important])>
                <span class="patient-notice__icon" aria-hidden="true"><i class="fa-solid {{ $announcement->is_important ? 'fa-triangle-exclamation' : 'fa-bullhorn' }}"></i></span>
                <div class="patient-notice__content">
                    <p class="patient-notice__from">
                        {{ $announcement->doctorProfile->user->name }}
                        @if ($announcement->is_important)<span class="patient-notice__flag">Important</span>@endif
                        <span class="patient-notice__date">· {{ $announcement->created_at->format('M j') }}</span>
                    </p>
                    <h3 class="patient-notice__title">{{ $announcement->title }}</h3>
                    <p class="patient-notice__body">{{ $announcement->body }}</p>
                </div>
                <form method="POST" action="{{ route('patient.announcements.dismiss', $announcement) }}">
                    @csrf
                    <button class="patient-notice__dismiss" type="submit" aria-label="Dismiss notice: {{ $announcement->title }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </form>
            </article>
        @endforeach
    </section>
@endif
