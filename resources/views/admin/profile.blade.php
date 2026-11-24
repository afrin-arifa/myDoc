@extends('layouts.app')

@section('title', 'My profile | myDoc')

@section('content')
    <div class="container py-5">
        <header class="doctor-detail__hero patient-profile__hero">
            <x-users.photo-editor :user="$user" form="admin-profile-form" visibility="Seen by administrators only." />
            <div class="doctor-detail__intro">
                <p class="doctor-detail__title">Administrator</p>
                <h1 class="doctor-detail__name">{{ $user->name }}</h1>
                <ul class="patient-profile__contact" aria-label="Contact details">
                    <li><i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ $user->email }}</li>
                    <li><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>{{ $user->phone ?: 'No mobile number yet' }}</li>
                </ul>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.assistants.create') }}"><i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>Add assistant</a>
            </div>
            <dl class="doctor-detail__facts">
                @if ($user->created_at)
                    <div><dt>Member since</dt><dd>{{ $user->created_at->format('M Y') }}</dd></div>
                @endif
                <div><dt>Role</dt><dd>{{ $user->roleLabel() }}</dd></div>
                <div><dt>Assistants</dt><dd>{{ $assistantCount }}</dd></div>
            </dl>
        </header>

        <div class="row g-4">
            <div class="col-lg-7">
                <form class="doctor-panel" id="admin-profile-form" method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data" aria-labelledby="personal-heading" data-disable-on-submit>
                    @csrf @method('PUT')
                    <div class="doctor-profile-section"><i class="fa-regular fa-user" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="personal-heading">Personal information</h2><p>Your administrator account details.</p></div></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="name">Name</label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="email" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="phone">Mobile number <span class="fw-normal text-secondary">(optional)</span></label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" maxlength="30" autocomplete="tel" placeholder="01712345678" aria-describedby="phone_help">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="phone_help">Bangladeshi mobile number, e.g. 01712345678 or +8801712345678.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">Address <span class="fw-normal text-secondary">(optional)</span></label>
                            <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address" rows="3" maxlength="1000" autocomplete="street-address">{{ old('address', $user->address) }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save profile</button>
                </form>

                <section class="doctor-panel mt-4" aria-labelledby="assistants-heading">
                    <div class="doctor-panel__header">
                        <div class="doctor-profile-section mb-0"><i class="fa-solid fa-user-nurse" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="assistants-heading">Doctor assistants</h2><p>{{ $assistantCount }} {{ Str::plural('assistant', $assistantCount) }} on myDoc.</p></div></div>
                        <a class="btn btn-primary btn-sm" href="{{ route('admin.assistants.create') }}"><i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>Add assistant</a>
                    </div>
                    @forelse ($recentAssistants as $assistant)
                        <div class="admin-profile__assistant">
                            <x-ui.avatar :name="$assistant->name" :src="$assistant->photoUrl()" size="sm" />
                            <div class="min-w-0 flex-grow-1"><strong>{{ $assistant->name }}</strong><span>{{ $assistant->email }} · {{ $assistant->assistant_assignments_count }} {{ Str::plural('doctor', $assistant->assistant_assignments_count) }}</span></div>
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.assistants.show', $assistant) }}">Manage<span class="visually-hidden"> {{ $assistant->name }}</span></a>
                        </div>
                    @empty
                        <p class="text-secondary mb-0">No assistants yet. Add the first one to help doctors with appointments and schedules.</p>
                    @endforelse
                    @if ($assistantCount > $recentAssistants->count())
                        <div class="mt-3"><a class="section-link" href="{{ route('admin.assistants.index') }}">View all assistants<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
                    @endif
                </section>
            </div>
            <div class="col-lg-5 d-grid gap-4 align-content-start">
                <x-forms.password-form />
            </div>
        </div>
    </div>
@endsection
