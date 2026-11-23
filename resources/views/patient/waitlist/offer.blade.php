@extends('layouts.app')

@section('title', 'Waiting list offer | myDoc')

@section('content')
    @php($slot = $offer->slot)
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4" href="{{ route('patient.waitlist.index') }}">&larr; Back to waiting list</a>
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <p class="hero__eyebrow mb-2">Waiting list offer</p>
                        <h1 class="h3 mb-3">A time with {{ $slot->doctorProfile->user->name }}</h1>

                        <dl class="row mb-4">
                            <dt class="col-sm-4">Date and time</dt>
                            <dd class="col-sm-8">{{ $slot->starts_at->format('D, M j, Y g:i A') }}</dd>
                            <dt class="col-sm-4">Location</dt>
                            <dd class="col-sm-8"><x-appointments.location :location="$slot->location" /></dd>
                            <dt class="col-sm-4">{{ App\Services\ConsultationFee::label($fee['type']) }}</dt>
                            <dd class="col-sm-8">{{ App\Services\ConsultationFee::format($fee['fee']) }}</dd>
                            <dt class="col-sm-4">Consultation</dt>
                            <dd class="col-sm-8"><x-appointments.consultation-type :type="$offer->entry->consultation_type" /></dd>
                        </dl>

                        @if ($offer->isOpen())
                            <div class="alert alert-info" role="status" data-offer-expires="{{ $offer->expires_at->toIso8601String() }}">
                                Held for you until <strong>{{ $offer->expires_at->format('g:i A') }}</strong> (Bangladesh time). After that it is offered to the next patient.
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('patient.waitlist.offers.accept', $offer) }}" data-disable-on-submit>@csrf
                                    <button class="btn btn-primary px-4" type="submit" data-loading-text="Sending request…">Accept and send request</button>
                                </form>
                                <form method="POST" action="{{ route('patient.waitlist.offers.decline', $offer) }}">@csrf
                                    <button class="btn btn-outline-secondary" type="submit">Decline</button>
                                </form>
                            </div>
                            <p class="small text-secondary mt-3 mb-0">Accepting sends an appointment request that the doctor confirms, like any other booking.</p>
                        @else
                            <div class="alert alert-secondary mb-0" role="status">
                                This offer is no longer open{{ $offer->status === App\Models\WaitlistOffer::STATUS_ACCEPTED ? ': you accepted it' : '' }}.
                                @if ($offer->entry->status === App\Models\WaitlistEntry::STATUS_WAITING) You are still on the waiting list. @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
