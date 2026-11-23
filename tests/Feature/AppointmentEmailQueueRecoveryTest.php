<?php

namespace Tests\Feature;

use App\Console\Commands\RequeuePendingAppointmentNotifications;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Exercises email delivery through Laravel's real database queue driver and worker, so reservation, expiry,
 * release, back-off and job deletion behave exactly as in production.
 */
class AppointmentEmailQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    public function test_job_redelivered_after_a_crash_is_released_not_deleted_and_recovers_after_the_stale_window(): void
    {
        $ledger = $this->bookAndGetLedger();
        $this->assertSame(1, $this->jobCount());

        // A worker reserves the job, claims the row, then dies before marking it sent or failed.
        $this->simulateWorkerCrashMidSend($ledger);
        $this->travel(config('queue.connections.database.retry_after') + 1)->seconds();

        // The queue redelivers the expired reservation. The claim is still fresh, so the job must survive.
        $this->work();

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'The redelivered job must stay in the queue while the claim is fresh.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, $job->attempts, 'Exactly one extra run is spent on the release.');
        $this->assertSame(AppointmentNotification::STATUS_SENDING, $ledger->fresh()->status);
        $this->assertSame(1, $ledger->fresh()->attempts);
        $this->assertCount(0, $this->sentEmails());

        $staleAt = $ledger->fresh()->updated_at->addMinutes(SendAppointmentNotification::STALE_CLAIM_MINUTES)->getTimestamp();
        $this->assertSame($staleAt + SendAppointmentNotification::RELEASE_BUFFER_SECONDS, (int) $job->available_at, 'Released once, to just after the claim goes stale.');

        // Before the release delay expires nothing runs, and nothing is lost.
        $this->travelTo(now()->setTimestamp((int) $job->available_at - 10));
        $this->work();
        $this->assertSame(1, $this->jobCount());
        $this->assertCount(0, $this->sentEmails());

        // After the stale window the released job reclaims the row and delivers exactly once.
        $this->travelTo(now()->setTimestamp((int) $job->available_at + 1));
        $this->work();

        $this->assertSame(0, $this->jobCount());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->fresh()->status);
        $this->assertSame(2, $ledger->fresh()->attempts);
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_sent_and_skipped_notifications_finish_without_release_or_resend(): void
    {
        foreach ([AppointmentNotification::STATUS_SENT, AppointmentNotification::STATUS_SKIPPED] as $finalStatus) {
            $ledger = $this->bookAndGetLedger();
            DB::table('jobs')->delete();
            $ledger->forceFill(['status' => $finalStatus, 'attempts' => 1])->save();

            SendAppointmentNotification::dispatch($ledger->id);
            $this->work();

            $this->assertSame(0, $this->jobCount(), "A {$finalStatus} notification's job must finish, not be released.");
            $this->assertSame(0, DB::table('failed_jobs')->count());
            $this->assertSame($finalStatus, $ledger->fresh()->status);
            $this->assertSame(1, $ledger->fresh()->attempts, 'A final row is never claimed again.');
        }

        $this->assertCount(0, $this->sentEmails());
    }

    public function test_an_email_that_fails_five_times_is_marked_exhausted_and_no_longer_claimable(): void
    {
        $this->freezeTime();
        $this->useFailingMailTransport();
        $ledger = $this->bookAndGetLedger();

        foreach (range(1, 5) as $attempt) {
            $this->work();
            $this->travel(1801)->seconds();
        }

        $this->assertSame(0, $this->jobCount());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'The queue gave up after five delivery failures.');
        $this->assertSame([AppointmentNotification::STATUS_EXHAUSTED, 5, 'Email delivery failed.'], [$ledger->fresh()->status, $ledger->fresh()->attempts, $ledger->fresh()->last_error]);

        config(['mail.default' => 'array']);
        (new SendAppointmentNotification($ledger->id))->handle();
        $this->assertCount(0, $this->sentEmails(), 'A stray job cannot send an exhausted row; only an admin retry can.');
        $this->assertSame(AppointmentNotification::STATUS_EXHAUSTED, $ledger->fresh()->status);
    }

    public function test_giving_up_closes_each_row_state_safely(): void
    {
        $cases = [
            'failed email' => [['channel' => 'email', 'status' => 'failed', 'last_error' => 'Email delivery failed.'], 'exhausted', 'Email delivery failed.'],
            'failed sms' => [['channel' => 'sms', 'status' => 'failed', 'last_error' => 'SMS balance is insufficient.'], 'exhausted', 'SMS balance is insufficient.'],
            'interrupted email' => [['channel' => 'email', 'status' => 'sending'], 'exhausted', 'Delivery attempts were interrupted repeatedly.'],
            'sms before the provider call' => [['channel' => 'sms', 'status' => 'sending'], 'exhausted', 'Delivery attempts were interrupted repeatedly.'],
            'sms after the provider call' => [['channel' => 'sms', 'status' => 'sending', 'provider_requested_at' => now()], 'unconfirmed', 'Delivery was interrupted after the SMS provider was contacted.'],
            'already sent' => [['channel' => 'email', 'status' => 'sent'], 'sent', null],
            'unconfirmed sms' => [['channel' => 'sms', 'status' => 'unconfirmed', 'last_error' => 'SMS provider did not respond in time.'], 'unconfirmed', 'SMS provider did not respond in time.'],
        ];
        $appointmentId = $this->bookAndGetLedger()->appointment_id;

        foreach ($cases as $case => [$attributes, $expectedStatus, $expectedError]) {
            $row = AppointmentNotification::query()->create(['appointment_id' => $appointmentId, 'type' => 'confirmed', 'transition_key' => $case, 'attempts' => 3, ...$attributes]);

            (new SendAppointmentNotification($row->id))->failed(new RuntimeException('gave up'));

            $this->assertSame([$expectedStatus, $expectedError], [$row->fresh()->status, $row->fresh()->last_error], $case);
        }
    }

    public function test_a_second_worker_cannot_claim_while_delivery_is_in_progress(): void
    {
        $ledger = $this->bookAndGetLedger();
        DB::table('jobs')->delete();
        $claim = new ReflectionMethod(SendAppointmentNotification::class, 'claim');

        $this->assertTrue($claim->invoke(new SendAppointmentNotification($ledger->id)));
        $this->assertFalse($claim->invoke(new SendAppointmentNotification($ledger->id)), 'A fresh claim cannot be taken twice.');
        AppointmentNotification::query()->whereKey($ledger->id)->update(['status' => AppointmentNotification::STATUS_PENDING, 'attempts' => 0]);

        // A competing worker runs while the first is in the middle of talking to the mail server.
        $competingAttemptSent = null;
        $this->useMailTransport(function () use ($ledger, &$competingAttemptSent): void {
            if ($competingAttemptSent === null) {
                $before = $this->sentEmails()->count();
                (new SendAppointmentNotification($ledger->id))->handle();
                $competingAttemptSent = $this->sentEmails()->count() > $before;
            }
        });

        SendAppointmentNotification::dispatch($ledger->id);
        $this->work();

        $this->assertFalse($competingAttemptSent, 'The competing worker must not deliver.');
        $this->assertSame(1, $this->recordingTransportCount());
        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->fresh()->status);
        $this->assertSame(0, $this->jobCount());
    }

    public function test_failed_smtp_attempt_stays_queued_for_retry_and_success_is_never_resent(): void
    {
        // Frozen so a second boundary between the worker run and the assertion cannot shift the expected back-off.
        $this->freezeTime();
        $this->useFailingMailTransport();
        $ledger = $this->bookAndGetLedger();

        $this->work();

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'A failed delivery must remain queued for retry.');
        $this->assertSame(AppointmentNotification::STATUS_FAILED, $ledger->fresh()->status);
        $this->assertSame('Email delivery failed.', $ledger->fresh()->last_error);
        $this->assertSame(now()->getTimestamp() + 60, (int) $job->available_at, 'First back-off is one minute.');

        config(['mail.default' => 'array']);
        $this->travel(61)->seconds();
        $this->work();

        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->fresh()->status);
        $this->assertNotNull($ledger->fresh()->sent_at);
        $this->assertSame(0, $this->jobCount());
        $this->assertCount(1, $this->sentEmails());

        // A late duplicate or manual queue retry must not deliver again.
        SendAppointmentNotification::dispatch($ledger->id);
        $this->work();
        $this->assertSame(0, $this->jobCount());
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame(2, $ledger->fresh()->attempts);
    }

    public function test_crash_recovery_does_not_consume_the_five_delivery_attempts(): void
    {
        $this->useFailingMailTransport();
        $ledger = $this->bookAndGetLedger();
        $this->simulateWorkerCrashMidSend($ledger);
        $this->travel(config('queue.connections.database.retry_after') + 1)->seconds();
        $this->work();
        $this->assertSame(2, DB::table('jobs')->value('attempts'), 'Interrupted run plus one release.');

        foreach (range(1, 5) as $deliveryAttempt) {
            $this->travelTo(now()->setTimestamp((int) DB::table('jobs')->value('available_at') + 1));
            $this->work();

            // Retrying while the job has delivery attempts left; marked exhausted once the queue gives up.
            $this->assertSame($deliveryAttempt < 5 ? AppointmentNotification::STATUS_FAILED : AppointmentNotification::STATUS_EXHAUSTED, $ledger->fresh()->status);
            if ($deliveryAttempt < 5) {
                $this->assertSame(1, $this->jobCount(), "Still retryable after {$deliveryAttempt} delivery failure(s).");
            }
        }

        $this->assertSame(0, $this->jobCount());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'The job fails only after five delivery failures.');
        $this->assertSame(6, $ledger->fresh()->attempts, 'One interrupted claim plus five delivery attempts.');
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_timeouts_are_ordered_so_a_hung_mail_server_cannot_outlive_the_job(): void
    {
        $job = new SendAppointmentNotification(1);
        $smtpTimeout = config('mail.mailers.smtp.timeout');
        $retryAfter = config('queue.connections.database.retry_after');

        $this->assertSame(20, $smtpTimeout);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(90, $retryAfter);
        $this->assertLessThanOrEqual($job->timeout / 2, $smtpTimeout, 'SMTP gives up well before the job is killed.');
        $this->assertLessThan($retryAfter, $job->timeout, 'A job is killed before the queue redelivers it.');
        $this->assertGreaterThan($retryAfter, SendAppointmentNotification::STALE_CLAIM_MINUTES * 60, 'A redelivered job sees a fresh claim and releases.');

        $stream = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream();
        $this->assertSame(20.0, (float) $stream->getTimeout(), 'The SMTP socket really uses the configured timeout.');
    }

    public function test_pending_notification_whose_job_was_lost_is_requeued_and_delivered_once(): void
    {
        // The appointment commits, but pushing the job is lost (the null queue discards it).
        config(['queue.default' => 'null']);
        $ledger = $this->bookAndGetLedger();
        config(['queue.default' => 'database']);
        $this->assertSame(0, $this->jobCount());
        $this->assertSame(AppointmentNotification::STATUS_PENDING, $ledger->fresh()->status);

        $this->artisan('mydoc:requeue-pending-notifications')->assertSuccessful();
        $this->assertSame(0, $this->jobCount(), 'Recent pending rows are left to their own job.');

        $this->travel(RequeuePendingAppointmentNotifications::PENDING_AFTER_MINUTES + 1)->minutes();
        $this->artisan('mydoc:requeue-pending-notifications')->expectsOutputToContain('1 pending')->assertSuccessful();
        $this->artisan('mydoc:requeue-pending-notifications')->expectsOutputToContain('0 pending')->assertSuccessful();
        $this->assertSame(1, $this->jobCount(), 'A row is requeued at most once per window.');
        $this->assertSame(AppointmentNotification::STATUS_PENDING, $ledger->fresh()->status, 'Requeueing never marks a row sent.');

        $this->work();

        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->fresh()->status);
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_requeue_ignores_rows_that_are_not_pending(): void
    {
        $ledgers = collect([
            AppointmentNotification::STATUS_SENDING,
            AppointmentNotification::STATUS_SENT,
            AppointmentNotification::STATUS_FAILED,
            AppointmentNotification::STATUS_SKIPPED,
        ])->map(function (string $status): AppointmentNotification {
            $ledger = $this->bookAndGetLedger();
            $ledger->forceFill(['status' => $status])->save();

            return $ledger;
        });
        DB::table('jobs')->delete();

        $this->travel(RequeuePendingAppointmentNotifications::PENDING_AFTER_MINUTES + 1)->minutes();
        $this->artisan('mydoc:requeue-pending-notifications')->expectsOutputToContain('0 pending')->assertSuccessful();

        $this->assertSame(0, $this->jobCount());
        $ledgers->each(fn (AppointmentNotification $ledger) => $this->assertSame($ledger->status, $ledger->fresh()->status));
    }

    public function test_requeue_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('mydoc:requeue-pending-notifications')->assertSuccessful();
    }

    private function work(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]);
    }

    private function simulateWorkerCrashMidSend(AppointmentNotification $ledger): void
    {
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->getTimestamp()]);
        AppointmentNotification::query()->whereKey($ledger->id)->update([
            'status' => AppointmentNotification::STATUS_SENDING,
            'attempts' => 1,
            'updated_at' => now(),
        ]);
    }

    private function bookAndGetLedger(): AppointmentNotification
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);
        $startsAt = now()->startOfHour()->addDays(3)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => 'available',
        ]);

        // A staff booking records exactly one email (to the patient, who has not opted in to SMS), so each test
        // works with a single ledger row and a single queued job.
        $appointment = app(AppointmentBookingService::class)
            ->bookForDoctor($doctor, User::factory()->create(['role' => 'patient']), $slot->id, 'physical');

        return AppointmentNotification::query()->where('appointment_id', $appointment->id)->firstOrFail();
    }

    private function jobCount(): int
    {
        return DB::table('jobs')->count();
    }

    private function sentEmails()
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    }

    private int $recordedByCustomTransport = 0;

    private function recordingTransportCount(): int
    {
        return $this->recordedByCustomTransport;
    }

    /**
     * Use a transport that runs $duringSend in the middle of delivering, then records the message.
     */
    private function useMailTransport(callable $duringSend): void
    {
        $test = $this;
        Mail::extend('recording', fn () => new class($duringSend, $test) extends AbstractTransport
        {
            public function __construct(private $duringSend, private AppointmentEmailQueueRecoveryTest $test)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                ($this->duringSend)();
                $this->test->recordCustomTransportDelivery();
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        });
        config(['mail.mailers.recording' => ['transport' => 'recording'], 'mail.default' => 'recording']);
    }

    public function recordCustomTransportDelivery(): void
    {
        $this->recordedByCustomTransport++;
    }

    private function useFailingMailTransport(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP unavailable');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    }
}
