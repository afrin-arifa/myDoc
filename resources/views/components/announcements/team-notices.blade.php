@props(['announcements', 'showDoctor' => false])
{{-- New announcements a colleague posted (the doctor or one of their assistants), on the staff dashboards. --}}
@if ($announcements->isNotEmpty())
    <section class="patient-notices mb-4" aria-labelledby="team-notices-heading">
        <h2 class="h6 mb-0" id="team-notices-heading"><i class="fa-solid fa-bullhorn text-primary me-2" aria-hidden="true"></i>New announcements from your team</h2>
        @foreach ($announcements as $announcement)
            <article @class(['patient-notice', 'is-important' => $announcement->is_important])>
                <span class="patient-notice__icon" aria-hidden="true"><i class="fa-solid {{ $announcement->is_important ? 'fa-triangle-exclamation' : 'fa-bullhorn' }}"></i></span>
                <div class="patient-notice__content">
                    <p class="patient-notice__from">
                        {{ $announcement->author?->name ?? 'Former account' }}{{ $announcement->author?->role === App\Models\User::ROLE_ASSISTANT ? ' (assistant)' : '' }}
                        @if ($showDoctor)<span class="patient-notice__date">· for {{ $announcement->doctorProfile->user->name }}'s patients</span>@endif
                        @if ($announcement->is_important)<span class="patient-notice__flag">Important</span>@endif
                        <span class="patient-notice__date">· {{ $announcement->created_at->format('M j, g:i A') }}</span>
                    </p>
                    <h3 class="patient-notice__title">{{ $announcement->title }}</h3>
                    <p class="patient-notice__body">{{ $announcement->body }}</p>
                    <p class="patient-notice__meta"><span><i class="fa-regular fa-eye" aria-hidden="true"></i>Patients see this on their dashboard{{ $announcement->expires_at ? ' until '.$announcement->expires_at->format('M j, Y') : '' }}.</span></p>
                </div>
                <form method="POST" action="{{ route('announcements.read', $announcement) }}">
                    @csrf
                    <button class="patient-notice__dismiss" type="submit" aria-label="Mark as read: {{ $announcement->title }}" title="Mark as read"><i class="fa-solid fa-check" aria-hidden="true"></i></button>
                </form>
            </article>
        @endforeach
    </section>
@endif
