<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsUnavailableSlotRedirect;
use Tests\TestCase;

/**
 * Release 1, Phase 4: directory and profile availability loading, read-only booking-rule hints, the "time is no
 * longer available" recovery, patient status explanations and cancellation confirmation.
 */
class DirectoryBookingUiTest extends TestCase
{
    use AssertsUnavailableSlotRedirect, RefreshDatabase;

    private const DHAKA = 'Asia/Dhaka';

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday 20 November 2026, 09:00 in Dhaka.
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', self::DHAKA));
        $this->patient = User::factory()->create(['role' => 'patient']);
    }

    public function test_the_directory_shows_the_next_open_time_without_loading_any_slots(): void
    {
        $doctor = $this->doctor('Dr. Aggregate');
        $this->slot($doctor, '2026-11-20 08:30');                 // started
        $this->slot($doctor, '2026-11-20 10:00', 'booked');
        $this->slot($doctor, '2026-11-20 10:30', 'blocked');
        $this->slot($doctor, '2026-11-21 11:15');                 // the next open time
        foreach (range(0, 39) as $offset) {
            $this->slot($doctor, '2026-11-25 08:00', secondOffset: $offset * 600);
        }
        $this->doctor('Dr. Nothing Open');

        $slotsLoaded = $this->countLoadedSlots();
        $response = $this->actingAs($this->patient)->get(route('doctors.index'))->assertOk();

        $this->assertSame(0, $slotsLoaded(), 'The directory reads one aggregate per doctor; no slot rows are loaded.');
        $response->assertSeeInOrder(['Dr. Aggregate', 'Next available', 'Sat, Nov 21, 11:15 AM'])
            ->assertSeeInOrder(['Dr. Nothing Open', 'No upcoming times listed'])
            ->assertSee(route('doctors.show', ['doctor' => $doctor, 'date' => '2026-11-21']).'#availability', false);
    }

    public function test_directory_queries_do_not_grow_with_the_number_of_doctors_or_slots(): void
    {
        $this->doctorsWithSlots(2, 1);
        $fewQueries = $this->countQueries(fn () => $this->actingAs($this->patient)->get(route('doctors.index'))->assertOk());

        $this->doctorsWithSlots(10, 20);
        $manyQueries = $this->countQueries(fn () => $this->actingAs($this->patient)->get(route('doctors.index'))->assertOk());

        $this->assertSame($fewQueries, $manyQueries, 'No N+1: 12 doctors with 20 slots each cost the same queries as 2.');
    }

    public function test_available_today_is_a_filter_that_combines_with_any_sort_in_a_stable_order(): void
    {
        $cheap = $this->doctor('Dr. Cheap Today', fee: 20);
        $dear = $this->doctor('Dr. Dear Today', fee: 90);
        $sameFeeA = $this->doctor('Dr. Alpha Today', fee: 50);
        $sameFeeB = $this->doctor('Dr. Beta Today', fee: 50);
        $tomorrowOnly = $this->doctor('Dr. Tomorrow', fee: 10);
        $startedOnly = $this->doctor('Dr. Started', fee: 10);
        foreach ([$cheap, $dear, $sameFeeA, $sameFeeB] as $doctor) {
            $this->slot($doctor, '2026-11-20 15:00');
        }
        $this->slot($tomorrowOnly, '2026-11-21 09:00');
        $this->slot($startedOnly, '2026-11-20 08:00');

        $today = $this->actingAs($this->patient)->get(route('doctors.index', ['filter' => 'today']))->assertOk()
            ->assertSeeInOrder(['Dr. Alpha Today', 'Dr. Beta Today', 'Dr. Cheap Today', 'Dr. Dear Today'])
            ->assertDontSee('Dr. Tomorrow')
            ->assertDontSee('Dr. Started')
            ->assertSeeText('4 doctors found');
        $this->assertMatchesRegularExpression('/aria-current="page"\s*>Available today/', $today->getContent());
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"\s*>All doctors/', $today->getContent());

        $this->actingAs($this->patient)->get(route('doctors.index', ['filter' => 'today', 'sort' => 'fee']))->assertOk()
            ->assertSeeInOrder(['Dr. Cheap Today', 'Dr. Alpha Today', 'Dr. Beta Today', 'Dr. Dear Today'])
            ->assertDontSee('Dr. Tomorrow');

        // Older links that used filter=fee still sort by fee.
        $this->actingAs($this->patient)->get(route('doctors.index', ['filter' => 'fee']))->assertOk()
            ->assertSeeInOrder(['Dr. Started', 'Dr. Tomorrow', 'Dr. Cheap Today'])
            ->assertSeeText('6 doctors listed');

        $this->actingAs($this->patient)->get(route('doctors.index', ['sort' => 'unknown']))->assertSessionHasErrors('sort');
    }

    public function test_only_patients_see_booking_actions_and_the_redundant_accepting_badge_is_gone(): void
    {
        $doctor = $this->doctor('Dr. Bookable');
        $this->slot($doctor, '2026-11-21 10:00');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($this->patient)->get(route('doctors.index'))->assertOk()
            ->assertSee('See available times')
            ->assertSee('alt=""', false)
            ->assertDontSee('Accepting patients');
        $this->actingAs($this->patient)->get(route('doctors.show', $doctor))->assertOk()
            ->assertDontSee('Accepting patients')
            ->assertSee(route('appointment-slots.confirm', AppointmentSlot::sole()), false);

        $this->actingAs($admin)->get(route('doctors.index'))->assertOk()
            ->assertDontSee('See available times')
            ->assertDontSee('Book now')
            ->assertSee('View profile');
        $this->actingAs($admin)->get(route('doctors.show', $doctor))->assertOk()
            ->assertSee('10:00 AM')
            ->assertDontSee(route('appointment-slots.confirm', AppointmentSlot::sole()), false);
    }

    public function test_the_profile_loads_only_the_selected_day_and_defaults_to_the_first_day_with_open_times(): void
    {
        $doctor = $this->doctor('Dr. Days');
        $this->slot($doctor, '2026-11-20 08:30');                 // started today: not offered
        $this->slot($doctor, '2026-11-22 09:00');
        $this->slot($doctor, '2026-11-22 09:30');
        $this->slot($doctor, '2026-11-22 10:00', 'booked');
        $this->slot($doctor, '2026-11-24 16:00');
        foreach (range(0, 29) as $offset) {
            $this->slot($doctor, '2026-12-01 08:00', secondOffset: $offset * 600);
        }

        $slotsLoaded = $this->countLoadedSlots();
        $response = $this->actingAs($this->patient)->get(route('doctors.show', $doctor))->assertOk();

        $this->assertSame(2, $slotsLoaded(), 'Only the selected day\'s open slots are loaded.');
        $response->assertSee('Sunday, Nov 22, 2026')
            ->assertSee('aria-label="Book Sun, Nov 22, 2026 at 9:00 AM"', false)
            ->assertSee('aria-label="Book Sun, Nov 22, 2026 at 9:30 AM"', false)
            ->assertDontSee('10:00 AM')
            ->assertDontSee('4:00 PM')
            ->assertSee('aria-current="date"', false)
            ->assertSee('Sunday, November 22: 2 open times')
            ->assertSee('Friday, November 20: no open times')
            ->assertSee('Thursday, December 3: no open times')
            ->assertDontSee('Friday, December 4', false);

        $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $doctor, 'date' => '2026-11-24']))->assertOk()
            ->assertSee('Tuesday, Nov 24, 2026')
            ->assertSee('aria-label="Book Tue, Nov 24, 2026 at 4:00 PM"', false)
            ->assertDontSee('9:30 AM');

        // A day with nothing open offers the next open time, even beyond the 14-day strip.
        $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $doctor, 'date' => '2026-11-25']))->assertOk()
            ->assertSee('No open times on this day.')
            ->assertSee('Next open time: Tue, Dec 1, 8:00 AM');
        $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $doctor, 'date' => '2026-12-01']))->assertOk()
            ->assertSee('aria-label="Book Tue, Dec 1, 2026 at 12:50 PM"', false);

        foreach (['2026-11-19', 'not-a-date', '2026-02-30', '2026-11-22T00:00'] as $invalid) {
            $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $doctor, 'date' => $invalid]))->assertOk()
                ->assertSee('Sunday, Nov 22, 2026');
        }
    }

    public function test_the_profile_defaults_beyond_the_strip_when_the_next_14_days_are_empty(): void
    {
        $doctor = $this->doctor('Dr. Far');
        $this->slot($doctor, '2026-12-10 10:00');
        $empty = $this->doctor('Dr. Empty');

        $this->actingAs($this->patient)->get(route('doctors.show', $doctor))->assertOk()
            ->assertSee('Thursday, Dec 10, 2026')
            ->assertSee('aria-label="Book Thu, Dec 10, 2026 at 10:00 AM"', false);

        $this->actingAs($this->patient)->get(route('doctors.show', $empty))->assertOk()
            ->assertSee('Friday, Nov 20, 2026')
            ->assertSee('This doctor has no future open times listed right now.');
    }

    public function test_overlapping_times_are_shown_disabled_with_the_reason_and_the_server_still_refuses_them(): void
    {
        $otherDoctor = $this->doctor('Dr. Other');
        $this->appointment($otherDoctor, '2026-11-22 09:00', 'confirmed');
        $doctor = $this->doctor('Dr. Overlap');
        $overlapping = $this->slot($doctor, '2026-11-22 09:05');
        $adjacent = $this->slot($doctor, '2026-11-22 09:10');

        $this->actingAs($this->patient)->get(route('doctors.show', $doctor))->assertOk()
            ->assertSee('Overlaps your appointment at 9:00 AM')
            ->assertDontSee(route('appointment-slots.confirm', $overlapping), false)
            ->assertSee('aria-label="Book Sun, Nov 22, 2026 at 9:10 AM"', false)
            ->assertSee(route('appointment-slots.confirm', $adjacent), false);

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $overlapping))->assertOk()
            ->assertSee('This time overlaps your appointment on Sun, Nov 22, 2026 at 9:00 AM')
            ->assertSee('Dr. Other')
            ->assertSee('disabled aria-describedby="booking-overlap-notice"', false);

        $this->actingAs($this->patient)->from(route('appointment-slots.confirm', $overlapping))
            ->post(route('appointment-slots.book', $overlapping), ['consultation_type' => 'physical'])
            ->assertRedirect(route('appointment-slots.confirm', $overlapping))
            ->assertSessionHasErrors('slot');
        $this->assertSame('available', $overlapping->fresh()->status);
    }

    public function test_at_the_active_limit_booking_is_disabled_with_a_notice_and_the_server_still_refuses(): void
    {
        $other = $this->doctor('Dr. Busy');
        foreach (['2026-11-23 09:00', '2026-11-24 09:00', '2026-11-25 09:00'] as $start) {
            $this->appointment($other, $start, 'booked');
        }
        $doctor = $this->doctor('Dr. Limit');
        $slot = $this->slot($doctor, '2026-11-22 11:00');

        $this->actingAs($this->patient)->get(route('doctors.show', $doctor))->assertOk()
            ->assertSee('You already have 3 active appointment requests')
            ->assertSee(route('patient.appointments.index'), false)
            ->assertSee('aria-describedby="booking-limit-notice"', false)
            ->assertDontSee(route('appointment-slots.confirm', $slot), false);

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()
            ->assertSee('You already have 3 active appointment requests')
            ->assertSee('disabled aria-describedby="booking-limit-notice"', false);

        $this->actingAs($this->patient)->from(route('appointment-slots.confirm', $slot))
            ->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertRedirect(route('appointment-slots.confirm', $slot))
            ->assertSessionHasErrors(['slot' => 'You already have 3 active appointment requests (pending or confirmed). Cancel one, or wait until one has taken place, before booking another.']);
        $this->assertSame(3, Appointment::count());

        // Below the limit, nothing is disabled.
        Appointment::query()->first()->update(['status' => 'cancelled']);
        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()
            ->assertDontSee('You already have 3 active appointment requests')
            ->assertDontSee('booking-limit-notice');
    }

    public function test_a_time_that_is_no_longer_open_sends_the_patient_back_to_the_profile_with_a_message(): void
    {
        $doctor = $this->doctor('Dr. Gone');
        $taken = $this->slot($doctor, '2026-11-22 09:00');
        $started = $this->slot($doctor, '2026-11-20 09:30');
        $this->slot($doctor, '2026-11-22 10:00');
        $this->actingAs(User::factory()->create(['role' => 'patient']))->post(route('appointment-slots.book', $taken), ['consultation_type' => 'physical']);

        $this->assertSentToChooseAnotherTime($this->actingAs($this->patient)->get(route('appointment-slots.confirm', $taken)), $taken);
        $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $doctor, 'date' => '2026-11-22']))->assertOk()
            ->assertSee('That appointment time is no longer available. Please choose another time.')
            ->assertSee('role="alert"', false)
            ->assertSee('aria-label="Book Sun, Nov 22, 2026 at 10:00 AM"', false);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:30', self::DHAKA));
        $this->assertSentToChooseAnotherTime($this->actingAs($this->patient)->get(route('appointment-slots.confirm', $started)), $started);
        $this->assertSentToChooseAnotherTime($this->actingAs($this->patient)->from(route('appointment-slots.confirm', $started))
            ->post(route('appointment-slots.book', $started), ['consultation_type' => 'physical']), $started);

        $this->assertSame(1, Appointment::count(), 'Only the first patient\'s request exists.');
        $this->postJson(route('appointment-slots.book', $taken), ['consultation_type' => 'physical'])->assertUnprocessable()->assertJsonValidationErrors('slot');

        // A doctor who is no longer listed is still never revealed.
        $doctor->update(['is_accepting_patients' => false]);
        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $taken))->assertNotFound();
    }

    public function test_patient_pages_explain_each_status_in_plain_words(): void
    {
        $doctor = $this->doctor('Dr. Explains');
        $cases = [
            ['2026-11-22 09:00', 'booked', 'This request is pending until the doctor approves it.'],
            ['2026-11-22 10:00', 'confirmed', 'The doctor has confirmed this appointment.'],
            ['2026-11-18 10:00', 'completed', 'This appointment has taken place.'],
            ['2026-11-22 11:00', 'rejected', 'The doctor could not accept this request.'],
            ['2026-11-22 12:00', 'cancelled', 'This appointment was cancelled and is no longer scheduled.'],
            ['2026-11-19 12:00', 'expired', 'This request expired because it was not approved before the appointment start time.'],
            ['2026-11-20 08:50', 'booked', 'so it will be marked as expired shortly'],
        ];

        foreach ($cases as [$start, $status, $explanation]) {
            $appointment = $this->appointment($doctor, $start, $status);
            $page = $this->actingAs($this->patient)->get(route('patient.appointments.show', $appointment))->assertOk()->assertSee($explanation);

            if (in_array($status, Appointment::CLOSED_STATUSES, true)) {
                $page->assertSee('Choose a new time with Dr. Explains');
            }
        }

        $this->actingAs($this->patient)->get(route('patient.appointments.index'))->assertOk()
            ->assertSee('This request expired because it was not approved before the appointment start time.')
            ->assertSee('The doctor could not accept this request.');
    }

    public function test_cancelling_asks_for_confirmation_and_still_cancels(): void
    {
        $doctor = $this->doctor('Dr. Cancel');
        $appointment = $this->appointment($doctor, '2026-11-25 10:00', 'booked');
        $confirmText = 'data-confirm="Cancel your appointment with Dr. Cancel on Wed, Nov 25, 2026 at 10:00 AM? This cannot be undone."';

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $appointment))->assertOk()->assertSee($confirmText, false);
        $this->actingAs($this->patient)->get(route('patient.appointments.index'))->assertOk()
            ->assertSee($confirmText, false)
            ->assertSee('<span class="visually-hidden"> with Dr. Cancel on Wed, Nov 25, 2026 at 10:00 AM</span>', false);

        $this->actingAs($this->patient)->post(route('appointments.cancel', $appointment))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_a_consultation_type_error_is_linked_to_its_inputs(): void
    {
        config(['booking.remote_consultations_enabled' => true]);
        $slot = $this->slot($this->doctor('Dr. Form'), '2026-11-22 09:00');

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()
            ->assertDontSee('aria-invalid', false);

        $this->actingAs($this->patient)->from(route('appointment-slots.confirm', $slot))
            ->post(route('appointment-slots.book', $slot), [])
            ->assertRedirect(route('appointment-slots.confirm', $slot))
            ->assertSessionHasErrors('consultation_type');

        $this->actingAs($this->patient)->from(route('appointment-slots.confirm', $slot))->followingRedirects()
            ->post(route('appointment-slots.book', $slot), [])->assertOk()
            ->assertSee('aria-describedby="consultation-type-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="consultation-type-error"', false);
    }

    private function doctor(string $name, float $fee = 50): DoctorProfile
    {
        $user = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return DoctorProfile::create(['user_id' => $user->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$user->id, 'consultation_fee' => $fee, 'years_of_experience' => 5, 'is_accepting_patients' => true])->load('user');
    }

    private function slot(DoctorProfile $doctor, string $dhakaStart, string $status = 'available', int $secondOffset = 0): AppointmentSlot
    {
        $startsAt = CarbonImmutable::parse($dhakaStart, self::DHAKA)->addSeconds($secondOffset);

        return AppointmentSlot::create(['doctor_profile_id' => $doctor->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $doctor, string $dhakaStart, string $status): Appointment
    {
        $slot = $this->slot($doctor, $dhakaStart, in_array($status, [...Appointment::ACTIVE_STATUSES, 'completed'], true) ? 'booked' : 'available');

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $this->patient->id, 'status' => $status, 'consultation_type' => 'physical', 'booked_at' => now()]);
    }

    private function doctorsWithSlots(int $doctors, int $slotsEach): void
    {
        foreach (range(1, $doctors) as $number) {
            $doctor = $this->doctor('Dr. Bulk '.str_pad((string) (DoctorProfile::count() + 1), 2, '0', STR_PAD_LEFT));

            foreach (range(0, $slotsEach - 1) as $offset) {
                $this->slot($doctor, '2026-11-21 08:00', secondOffset: $offset * 600);
            }
        }
    }

    /**
     * @return callable(): int Number of AppointmentSlot models hydrated since this call.
     */
    private function countLoadedSlots(): callable
    {
        $loaded = 0;
        Event::listen('eloquent.retrieved: '.AppointmentSlot::class, function () use (&$loaded): void {
            $loaded++;
        });

        return function () use (&$loaded): int {
            return $loaded;
        };
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
