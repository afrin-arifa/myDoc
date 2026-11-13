<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

class AppointmentSmsReminderTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    private User $doctor;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSms();
        $this->travelTo(Carbon::parse('2026-11-20 08:00:00'));
        $this->doctor = $this->smsDoctor();
        $this->patient = $this->smsPatient();
    }

    public function test_sends_one_24_hour_and_one_2_hour_reminder_and_never_duplicates(): void
    {
        $appointment = $this->bookedEarly(now()->addHours(24)->subMinutes(10), 'confirmed');

        $this->runReminders();
        $this->runReminders();
        $this->assertCount(1, $this->sms->sent, 'Re-running the scheduler never duplicates a reminder.');
        $this->assertStringStartsWith('myDoc reminder: Your appointment '.$appointment->reference(), $this->sms->sent[0]['message']);
        $this->assertStringContainsString('Dr. Rowan Hale', $this->sms->sent[0]['message']);
        $this->assertStringNotContainsString('symptom', $this->sms->sent[0]['message']);

        $this->travelTo($appointment->slot->starts_at->copy()->subHours(4));
        $this->runReminders();
        $this->assertCount(1, $this->sms->sent, 'Nothing is due between the two reminders.');

        $this->travelTo($appointment->slot->starts_at->copy()->subHours(2)->addMinutes(3));
        $this->runReminders();
        $this->runReminders();

        $this->assertCount(2, $this->sms->sent);
        $this->assertSame([24, 2], $this->smsLedger('reminder')->map(fn ($row) => $row->context['reminder_hours'])->all());
        $this->assertTrue($this->smsLedger('reminder')->every(fn ($row) => $row->status === 'sent' && $row->recipient_id === $this->patient->id));
        $this->assertSame(0, AppointmentNotification::query()->where('channel', 'email')->count(), 'Reminders are SMS only.');
    }

    public function test_only_booked_or_confirmed_appointments_get_reminders(): void
    {
        foreach (['booked', 'confirmed', 'cancelled', 'rejected', 'completed'] as $offset => $status) {
            $this->bookedEarly(now()->addHours(2)->subMinutes(5 + $offset), $status);
        }

        $this->runReminders();

        $this->assertSame(['booked', 'confirmed'], $this->smsLedger('reminder')->map(fn ($row) => $row->appointment->status)->all());
        $this->assertCount(2, $this->sms->sent);
    }

    public function test_a_reminder_queued_before_a_cancellation_is_not_sent(): void
    {
        $appointment = $this->bookedEarly(now()->addHours(24)->subMinutes(10));

        $this->holdJobs();
        $this->runReminders();
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->deliverHeld();

        $this->assertSame([], $this->sms->sent);
        $this->assertSame('Appointment changed before the SMS was sent.', $this->smsLedger('reminder')->first()->last_error);
    }

    public function test_rescheduling_retires_old_reminders_and_new_slot_gets_its_own(): void
    {
        $appointment = $this->bookedEarly(now()->addHours(24)->subMinutes(10), 'confirmed');

        $this->holdJobs();
        $this->runReminders();
        $newSlot = $this->smsSlot($this->doctor, now()->addDays(3));
        $this->actingAs($this->doctor)->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $newSlot->id])->assertRedirect();
        $this->deliverHeld();

        $oldReminder = $this->smsLedger('reminder')->first();
        $this->assertSame('skipped', $oldReminder->status, 'A reminder for the old slot is never sent.');
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('has moved to', $this->sms->sent[0]['message']);

        $this->app['config']->set('queue.default', 'sync');
        $this->travelTo($newSlot->starts_at->copy()->subHours(24)->addMinutes(5));
        $this->runReminders();

        $this->assertCount(2, $this->sms->sent);
        $this->assertStringContainsString($newSlot->starts_at->format('j M Y, g:i A'), $this->sms->sent[1]['message']);
        $this->assertSame($newSlot->id, $this->smsLedger('reminder')->last()->context['slot_id']);
    }

    public function test_a_slot_moved_back_to_the_same_slot_id_with_a_new_time_does_not_reuse_the_old_reminder(): void
    {
        $appointment = $this->bookedEarly(now()->addHours(2)->subMinutes(10));

        $this->holdJobs();
        $this->runReminders();
        $appointment->slot->update(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(5)->addMinutes(10)]);
        $this->deliverHeld();

        $this->assertSame([], $this->sms->sent);
        $this->assertSame('skipped', $this->smsLedger('reminder')->first()->status);
    }

    public function test_late_bookings_skip_reminders_that_were_already_due(): void
    {
        // Booked only three hours before the appointment starts.
        $appointment = $this->smsAppointment($this->doctor, $this->patient, now()->addHours(30));
        $appointment->forceFill(['created_at' => $appointment->slot->starts_at->copy()->subHours(3)])->save();

        $this->travelTo($appointment->slot->starts_at->copy()->subHours(24)->addMinutes(5));
        $this->runReminders();
        $this->assertSame([], $this->sms->sent, 'Not yet booked when the 24-hour reminder was due.');

        $this->travelTo($appointment->slot->starts_at->copy()->subHours(2)->addMinutes(5));
        $this->runReminders();
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(2, $this->smsLedger('reminder')->first()->context['reminder_hours']);
    }

    public function test_reminders_missed_beyond_the_catch_up_window_are_not_sent_late(): void
    {
        $appointment = $this->bookedEarly(now()->addHours(22));

        $this->runReminders();

        $this->assertSame([], $this->sms->sent, 'The 24-hour reminder is two hours overdue: outside the 60 minute window.');
        $this->travelTo($appointment->slot->starts_at->copy()->subMinutes(30));
        $this->runReminders();
        $this->assertSame([], $this->sms->sent, 'The 2-hour reminder is 90 minutes overdue.');
    }

    public function test_opted_out_or_deactivated_patients_get_no_reminders(): void
    {
        $optedOut = $this->smsPatient('01912345678', enabled: false);
        $deactivated = $this->smsPatient('01612345678', attributes: ['deactivated_at' => now()]);
        $this->bookedEarly(now()->addHours(2)->subMinutes(5), patient: $optedOut);
        $this->bookedEarly(now()->addHours(2)->subMinutes(6), patient: $deactivated);

        $this->runReminders();

        $this->assertSame([], $this->sms->sent);
        $this->assertCount(0, $this->smsLedger());
    }

    public function test_reminder_offsets_are_configurable(): void
    {
        config(['sms.reminders.hours' => [6]]);
        $this->bookedEarly(now()->addHours(24)->subMinutes(10));
        $six = $this->bookedEarly(now()->addHours(6)->subMinutes(10));

        $this->runReminders();

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($six->id, $this->smsLedger('reminder')->first()->appointment_id);
    }

    public function test_reminder_processing_is_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'mydoc:queue-appointment-reminders'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertCount(1, collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'mydoc:requeue-pending-notifications')));
    }

    private function bookedEarly(\DateTimeInterface $startsAt, string $status = 'booked', ?User $patient = null): Appointment
    {
        $appointment = $this->smsAppointment($this->doctor, $patient ?? $this->patient, $startsAt, $status);
        $appointment->forceFill(['created_at' => now()->subDays(3)])->save();

        return $appointment->fresh(['slot']);
    }

    private function runReminders(): void
    {
        $this->artisan('mydoc:queue-appointment-reminders')->assertSuccessful();
    }

    private function holdJobs(): void
    {
        $this->app['config']->set('queue.default', 'database');
    }

    private function deliverHeld(): void
    {
        foreach (AppointmentNotification::query()->where('status', 'pending')->orderBy('id')->pluck('id') as $id) {
            (new SendAppointmentNotification($id))->handle();
        }
    }
}
