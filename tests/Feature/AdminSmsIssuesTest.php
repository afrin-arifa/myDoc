<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

class AdminSmsIssuesTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    private User $admin;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->smsDoctor('01812345678', name: 'Dr. Hidden Name');
        $patient = $this->smsPatient('01712345678', attributes: ['name' => 'Patient Secret Name', 'email' => 'secret.patient@example.com']);
        $this->appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3));
    }

    public function test_only_active_admins_can_open_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk()->assertSee('Notification delivery issues');

        $this->actingAs($this->appointment->patient)->get(route('admin.sms-issues.index'))->assertForbidden();
        $this->actingAs($this->appointment->slot->doctorProfile->user)->get(route('admin.sms-issues.index'))->assertForbidden();

        $deactivated = User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);
        $this->actingAs($deactivated)->get(route('admin.sms-issues.index'))->assertForbidden();
        $this->assertFalse($deactivated->can('viewAny', AppointmentNotification::class));

        $this->app['auth']->forgetGuards();
        $this->get(route('admin.sms-issues.index'))->assertRedirect(route('login'));
    }

    public function test_lists_only_sms_problems_with_safe_metadata(): void
    {
        $unconfirmed = $this->issue('unconfirmed', [
            'type' => 'reminder',
            'provider_reference' => '1005',
            'last_error' => 'Provider reported an internal error; delivery is unknown.',
            'provider_requested_at' => now()->subMinutes(5),
        ]);
        $failed = $this->issue('failed', ['type' => 'confirmed', 'provider_reference' => '1007', 'last_error' => 'SMS balance is insufficient.', 'attempts' => 2]);
        $undelivered = $this->issue('undelivered', ['type' => 'booked', 'provider_reference' => '1002', 'last_error' => 'Sender ID is incorrect or disabled.']);
        foreach (['sent', 'skipped', 'pending', 'sending'] as $status) {
            $this->issue($status, ['last_error' => "Not an issue: {$status}"]);
        }
        $failedEmail = $this->issue('failed', ['channel' => 'email', 'type' => 'expired', 'last_error' => 'Email delivery failed.']);
        $exhaustedEmail = $this->issue('exhausted', ['channel' => 'email', 'type' => 'staff_cancelled', 'last_error' => 'Email delivery failed.']);
        $this->issue('sent', ['channel' => 'email', 'last_error' => 'Not an issue: sent email']);

        $page = $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk();

        $page->assertViewHas('issues', fn ($issues) => $issues->pluck('id')->sort()->values()->all() === collect([$unconfirmed->id, $failed->id, $undelivered->id, $failedEmail->id, $exhaustedEmail->id])->sort()->values()->all());
        $page->assertSee('#'.$unconfirmed->id)
            ->assertSee($this->appointment->reference())
            ->assertSee(route('admin.appointments.show', $this->appointment), false)
            ->assertSee('Reminder')->assertSee('Confirmation')->assertSee('Booking')
            ->assertSee('Unconfirmed')->assertSee('Failed')->assertSee('Undelivered')
            ->assertSee('1005')->assertSee('1007')->assertSee('1002')
            ->assertSee('Provider reported an internal error; delivery is unknown.')
            ->assertSee($unconfirmed->provider_requested_at->format('M j, Y g:i A'))
            ->assertSee('Not contacted')
            ->assertSee('2 attempts')
            ->assertSee('Expiry')->assertSee('Cancellation by clinic')->assertSee('Retries exhausted')
            ->assertSee('Email delivery failed.')
            ->assertDontSee('Not an issue');
        $this->assertSame(['unconfirmed' => 1, 'failed' => 2, 'undelivered' => 1, 'exhausted' => 1], $page->viewData('counts')->all());

        $emailOnly = $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['channel' => 'email']))->assertOk();
        $emailOnly->assertViewHas('issues', fn ($issues) => $issues->pluck('id')->sort()->values()->all() === [$failedEmail->id, $exhaustedEmail->id]);
        $this->assertSame(['unconfirmed' => 0, 'failed' => 1, 'undelivered' => 0, 'exhausted' => 1], $emailOnly->viewData('counts')->all());
        $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['channel' => 'sms']))->assertOk()
            ->assertViewHas('issues', fn ($issues) => $issues->count() === 3 && $issues->every(fn ($issue) => $issue->channel === 'sms'));
        $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['channel' => 'fax']))->assertSessionHasErrors('channel');
    }

    public function test_never_exposes_phones_message_text_recipients_or_credentials(): void
    {
        config(['sms.drivers.bulksmsbd.api_key' => 'SECRET-API-KEY-123', 'sms.sender_id' => '8809609999999']);
        $this->issue('unconfirmed', [
            'recipient_id' => $this->appointment->patient_id,
            'context' => ['audience' => 'patient', 'reminder_hours' => 24, 'slot_id' => $this->appointment->appointment_slot_id],
            'transition_key' => 'reminder:24h:slot:'.$this->appointment->appointment_slot_id.':1790000000:patient',
            'last_error' => 'SMS provider did not respond in time.',
            'provider_reference' => 'timeout',
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk()->getContent();

        foreach (['01712345678', '8801712345678', '1712345678', '1812345678', 'Patient Secret Name', 'secret.patient@example.com',
            'Dr. Hidden Name', 'Private symptom description', 'myDoc reminder', 'Your appointment', 'SECRET-API-KEY-123',
            '8809609999999', 'reminder:24h', 'audience'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $html, "The page must not show: {$sensitive}");
        }
    }

    public function test_only_messages_certainly_not_delivered_offer_a_retry(): void
    {
        foreach (['unconfirmed', 'failed', 'undelivered'] as $status) {
            $this->issue($status);
        }
        $this->issue('failed', ['channel' => 'email']);
        // An SMS that reached the provider can never be retried, even if it were marked exhausted.
        $contacted = $this->issue('exhausted', ['provider_requested_at' => now()]);

        $html = $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk()->getContent();
        $main = substr($html, strpos($html, '<main'));

        $this->assertStringNotContainsString('method="POST"', $main, 'Unconfirmed, undelivered and still-retrying messages offer no action.');
        $this->assertStringNotContainsStringIgnoringCase('resend', $main);
        $this->assertFalse($this->admin->can('retry', $contacted));

        $exhaustedSms = $this->issue('exhausted');
        $exhaustedEmail = $this->issue('exhausted', ['channel' => 'email']);
        $html = $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'method="POST" action="'.url('/admin/sms-issues')));
        $this->assertStringContainsString(route('admin.sms-issues.retry', $exhaustedSms), $html);
        $this->assertStringContainsString(route('admin.sms-issues.retry', $exhaustedEmail), $html);
        $this->assertStringContainsString('data-confirm="Retry notification #'.$exhaustedEmail->id, $html);

        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'sms-issues'));
        $this->assertEqualsCanonicalizing([['GET', 'HEAD'], ['POST']], $routes->map(fn ($route) => $route->methods())->values()->all());
    }

    public function test_an_admin_retry_queues_an_exhausted_message_once_and_it_is_delivered_once(): void
    {
        Queue::fake();
        $email = $this->issue('exhausted', ['channel' => 'email', 'type' => 'confirmed', 'transition_key' => 'confirmed', 'last_error' => 'Email delivery failed.', 'attempts' => 5]);

        $this->actingAs($this->admin)->from(route('admin.sms-issues.index'))->post(route('admin.sms-issues.retry', $email))
            ->assertRedirect(route('admin.sms-issues.index'))
            ->assertSessionHas('status', 'Notification #'.$email->id.' was queued for another delivery attempt.');
        $this->assertSame(['pending', 'Retry requested by an administrator.'], [$email->fresh()->status, $email->fresh()->last_error]);
        Queue::assertPushed(SendAppointmentNotification::class, fn (SendAppointmentNotification $job) => $job->notificationId === $email->id);

        // A second click (or a second admin at the same moment) queues nothing more.
        $this->actingAs($this->admin)->post(route('admin.sms-issues.retry', $email))->assertForbidden();
        Queue::assertPushed(SendAppointmentNotification::class, 1);

        $this->appointment->update(['status' => 'confirmed']);
        (new SendAppointmentNotification($email->id))->handle();
        (new SendAppointmentNotification($email->id))->handle();
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertCount(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_retry_is_refused_for_other_statuses_and_non_admins(): void
    {
        Queue::fake();
        foreach (['unconfirmed', 'undelivered', 'failed', 'sent', 'skipped', 'pending', 'sending'] as $status) {
            $row = $this->issue($status);
            $this->actingAs($this->admin)->post(route('admin.sms-issues.retry', $row))->assertForbidden();
            $this->assertSame($status, $row->fresh()->status);
        }
        $this->actingAs($this->admin)->post(route('admin.sms-issues.retry', $this->issue('exhausted', ['provider_requested_at' => now()])))->assertForbidden();

        $exhausted = $this->issue('exhausted');
        $deactivated = User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);
        foreach ([$this->appointment->patient, $this->appointment->slot->doctorProfile->user, $deactivated] as $user) {
            $this->actingAs($user)->post(route('admin.sms-issues.retry', $exhausted))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post(route('admin.sms-issues.retry', $exhausted))->assertRedirect(route('login'));

        $this->assertSame('exhausted', $exhausted->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_filters_by_status_and_rejects_other_statuses(): void
    {
        $unconfirmed = $this->issue('unconfirmed');
        $this->issue('failed');
        $this->issue('undelivered');

        $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => 'unconfirmed']))->assertOk()
            ->assertViewHas('issues', fn ($issues) => $issues->pluck('id')->all() === [$unconfirmed->id]);
        foreach (['failed', 'undelivered'] as $status) {
            $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => $status]))->assertOk()
                ->assertViewHas('issues', fn ($issues) => $issues->count() === 1 && $issues->first()->status === $status);
        }

        foreach (['sent', 'skipped', 'pending', "unconfirmed' or 1=1 --"] as $invalid) {
            $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => $invalid]))->assertSessionHasErrors('status');
        }
    }

    public function test_paginates_newest_first_and_keeps_the_filter(): void
    {
        foreach (range(1, 23) as $number) {
            $this->travel(1)->minutes();
            $this->issue('unconfirmed', ['transition_key' => "reminder:{$number}"]);
        }
        $this->issue('failed');

        $first = $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => 'unconfirmed']))->assertOk();
        $first->assertViewHas('issues', fn ($issues) => $issues->count() === 20 && $issues->total() === 23
            && $issues->first()->updated_at->gte($issues->last()->updated_at));
        $first->assertSee('status=unconfirmed&amp;page=2', false);

        $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => 'unconfirmed', 'page' => 2]))->assertOk()
            ->assertViewHas('issues', fn ($issues) => $issues->count() === 3 && $issues->every(fn ($issue) => $issue->status === 'unconfirmed'));
    }

    public function test_empty_state_and_admin_navigation_link(): void
    {
        $this->actingAs($this->admin)->get(route('admin.sms-issues.index'))->assertOk()->assertSee('No notification delivery issues');
        $this->actingAs($this->admin)->get(route('admin.sms-issues.index', ['status' => 'failed']))->assertOk()->assertSee('No matching delivery issues');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.sms-issues.index'), false)->assertSee('Notification delivery issues');
        $this->actingAs($this->appointment->patient)->get(route('patient.dashboard'))->assertOk()->assertDontSee(route('admin.sms-issues.index'), false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(string $status, array $attributes = []): AppointmentNotification
    {
        static $sequence = 0;
        $sequence++;

        return AppointmentNotification::query()->create([
            'appointment_id' => $this->appointment->id,
            'channel' => 'sms',
            'type' => 'reminder',
            'transition_key' => "issue-{$sequence}",
            'status' => $status,
            'attempts' => 1,
            ...$attributes,
        ]);
    }
}
