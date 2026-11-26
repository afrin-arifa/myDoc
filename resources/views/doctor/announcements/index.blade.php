@extends('layouts.app')

@section('title', 'Announcements | myDoc')

@section('content')
    <div class="container py-5">
        <div class="doctor-page-header">
            <div>
                <p class="doctor-eyebrow">Patients</p>
                <h1 class="doctor-page-title">Announcements</h1>
                <p class="text-secondary mb-0">Share news with {{ $workspace->inAssistantWorkspace() ? $profile->user->name."'s" : 'your' }} patients, such as a closed day or changed hours. Only {{ $workspace->inAssistantWorkspace() ? 'the doctor’s' : 'your' }} patients see them, on their dashboard.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-5">
                <section class="card border-0 shadow-sm p-4 announcement-compose" aria-labelledby="compose-heading">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="announcement-compose__icon" aria-hidden="true"><i class="fa-solid fa-bullhorn"></i></span>
                        <div>
                            <h2 class="h5 mb-0" id="compose-heading">New announcement</h2>
                            <p class="small text-secondary mb-0">Visible to {{ $audienceCount }} {{ Str::plural('patient', $audienceCount) }}.</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ $workspace->url('doctor.announcements.store') }}" data-disable-on-submit>
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="title">Title</label>
                            <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" required maxlength="{{ App\Models\DoctorAnnouncement::TITLE_MAX_LENGTH }}" placeholder="e.g. Clinic closed on Friday">
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="body">Message</label>
                            <textarea class="form-control @error('body') is-invalid @enderror" id="body" name="body" rows="4" required maxlength="{{ App\Models\DoctorAnnouncement::BODY_MAX_LENGTH }}" aria-describedby="body_help">{{ old('body') }}</textarea>
                            <div class="form-text" id="body_help">Up to {{ App\Models\DoctorAnnouncement::BODY_MAX_LENGTH }} characters. Do not include any patient's personal or medical details.</div>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="expires_on">Show until (optional)</label>
                            <input class="form-control @error('expires_on') is-invalid @enderror" id="expires_on" name="expires_on" type="date" value="{{ old('expires_on') }}" min="{{ today()->toDateString() }}" aria-describedby="expires_help">
                            <div class="form-text" id="expires_help">It disappears after this day. Leave empty to show it until you remove it.</div>
                            @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" id="is_important" name="is_important" type="checkbox" value="1" role="switch" @checked(old('is_important'))>
                            <label class="form-check-label" for="is_important">Mark as important <span class="small text-secondary">(highlighted, shown first)</span></label>
                        </div>
                        <button class="btn btn-primary" type="submit" data-loading-text="Posting…"><i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>Post announcement</button>
                    </form>
                </section>
            </div>

            <div class="col-xl-7">
                <section aria-labelledby="posted-heading">
                    <h2 class="h5 mb-3" id="posted-heading">Posted</h2>
                    @forelse ($announcements as $announcement)
                        <article @class(['announcement-card', 'is-important' => $announcement->is_important, 'is-expired' => ! $announcement->isActive()])>
                            <div class="announcement-card__top">
                                <div class="min-w-0">
                                    <h3 class="announcement-card__title">{{ $announcement->title }}</h3>
                                    <p class="announcement-card__meta">
                                        {{ $announcement->author?->name ?? 'Former account' }}{{ $announcement->author?->role === App\Models\User::ROLE_ASSISTANT ? ' (assistant)' : '' }}
                                        · {{ $announcement->created_at->format('M j, Y g:i A') }}
                                    </p>
                                </div>
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    @if (in_array($announcement->id, $unreadIds, true))<span class="patient-notice__new align-self-center">New</span>@endif
                                    @if ($announcement->is_important)<span class="status-badge status-badge--warning">Important</span>@endif
                                    <span @class(['status-badge', 'status-badge--success' => $announcement->isActive(), 'status-badge--neutral' => ! $announcement->isActive()])>{{ $announcement->isActive() ? 'Showing' : 'Ended' }}</span>
                                </div>
                            </div>
                            <p class="announcement-card__body">{{ $announcement->body }}</p>
                            <div class="announcement-card__footer">
                                <span><i class="fa-regular fa-calendar me-1" aria-hidden="true"></i>{{ $announcement->expires_at ? ($announcement->isActive() ? 'Until ' : 'Ended ').$announcement->expires_at->format('M j, Y') : 'No end date' }}</span>
                                <span><i class="fa-regular fa-eye-slash me-1" aria-hidden="true"></i>Dismissed by {{ $announcement->dismissed_by_count }} {{ Str::plural('patient', $announcement->dismissed_by_count) }}</span>
                                <form class="ms-auto" method="POST" action="{{ $workspace->url('doctor.announcements.destroy', $announcement) }}" data-confirm="Remove “{{ $announcement->title }}”? Patients will no longer see it.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <x-ui.empty-state class="py-5" icon="fa-solid fa-bullhorn" title="No announcements yet" message="Post one and it will show on your patients' dashboards." />
                    @endforelse
                    <div class="mt-3">{{ $announcements->links() }}</div>
                </section>
            </div>
        </div>
    </div>
@endsection
