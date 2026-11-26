@extends('layouts.app')

@section('title', 'Notices | myDoc')

@section('content')
    <div class="container py-5 patient-notices-page">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-2">Notices</h1>
        <p class="text-secondary mb-4">News from your doctors, such as closed days or changed hours. Only your own doctors' notices appear here.</p>

        @if ($announcements->isEmpty())
            <x-ui.empty-state class="text-center py-5" icon="fa-solid fa-bullhorn" title="No notices right now"
                message="When one of your doctors shares news, it will appear here and on your dashboard." />
        @else
            <div class="patient-notices">
                @foreach ($announcements as $announcement)
                    <article @class(['patient-notice', 'is-important' => $announcement->is_important, 'is-read' => $announcement->is_read])>
                        <span class="patient-notice__icon" aria-hidden="true"><i class="fa-solid {{ $announcement->is_important ? 'fa-triangle-exclamation' : 'fa-bullhorn' }}"></i></span>
                        <div class="patient-notice__content">
                            <p class="patient-notice__from">
                                {{ $announcement->doctorProfile->user->name }}
                                @if ($announcement->doctorProfile->specialties->isNotEmpty())
                                    <span class="patient-notice__date">· {{ $announcement->doctorProfile->specialties->pluck('name')->join(', ') }}</span>
                                @endif
                                @if ($announcement->is_important)<span class="patient-notice__flag">Important</span>@endif
                                @unless ($announcement->is_read)<span class="patient-notice__new">New</span>@endunless
                            </p>
                            <h2 class="patient-notice__title">{{ $announcement->title }}</h2>
                            <p class="patient-notice__body">{{ $announcement->body }}</p>
                            <p class="patient-notice__meta">
                                <span><i class="fa-regular fa-clock" aria-hidden="true"></i>Posted {{ $announcement->created_at->format('M j, Y g:i A') }}</span>
                                @if ($announcement->expires_at)
                                    <span><i class="fa-regular fa-calendar" aria-hidden="true"></i>Until {{ $announcement->expires_at->format('M j, Y') }}</span>
                                @endif
                            </p>
                        </div>
                        @unless ($announcement->is_read)
                            <form method="POST" action="{{ route('patient.announcements.dismiss', $announcement) }}">
                                @csrf
                                <input type="hidden" name="from_list" value="1">
                                <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Mark as read<span class="visually-hidden">: {{ $announcement->title }}</span></button>
                            </form>
                        @endunless
                    </article>
                @endforeach
            </div>
            <div class="mt-4">{{ $announcements->links() }}</div>
        @endif
    </div>
@endsection
