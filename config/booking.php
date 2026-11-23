<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote consultations
    |--------------------------------------------------------------------------
    |
    | Voice and video consultations need a call provider, which is planned for Release 2. While this is false,
    | patients and staff can only book physical visits and no "Start" call action is offered. Existing voice or
    | video appointments keep their type and are still displayed.
    |
    */

    'remote_consultations_enabled' => (bool) env('BOOKING_REMOTE_CONSULTATIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Active appointment limit
    |--------------------------------------------------------------------------
    |
    | The most pending or confirmed appointments a patient may hold at once, counting only appointments that have
    | not ended yet. Applies to every booking for the patient, whoever creates it.
    |
    */

    'max_active_appointments_per_patient' => (int) env('BOOKING_MAX_ACTIVE_APPOINTMENTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Waiting list
    |--------------------------------------------------------------------------
    |
    | When a matching time opens, it is held for the next waiting patient for hold_minutes, but every offer ends at
    | least cutoff_minutes before the slot starts; a slot that cannot be offered for min_offer_minutes stays open for
    | normal booking. A patient may hold max_active_entries waiting entries (one per doctor), each covering at most
    | max_range_days days, and an entry is paused after pause_after_expired_offers unanswered offers.
    |
    */

    'waitlist' => [
        'hold_minutes' => (int) env('WAITLIST_HOLD_MINUTES', 45),
        'cutoff_minutes' => (int) env('WAITLIST_CUTOFF_MINUTES', 30),
        'min_offer_minutes' => (int) env('WAITLIST_MIN_OFFER_MINUTES', 15),
        'max_active_entries' => (int) env('WAITLIST_MAX_ACTIVE_ENTRIES', 3),
        'max_range_days' => (int) env('WAITLIST_MAX_RANGE_DAYS', 14),
        'pause_after_expired_offers' => (int) env('WAITLIST_PAUSE_AFTER_EXPIRED_OFFERS', 2),
    ],

];
