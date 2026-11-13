<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Services\Sms\BulkSmsBdGateway;
use App\Services\Sms\SmsDeliveryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

class AppointmentSmsDeliveryTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSms();
        config(['queue.default' => 'database']);
    }

    public function test_retryable_failures_are_retried_with_backoff_and_delivered_once(): void
    {
        $this->sms->failUsing(function (int $attempt): void {
            if ($attempt <= 2) {
                throw SmsDeliveryException::retryable('SMS balance is insufficient.', '1007');
            }
        });
        $row = $this->confirmedSmsRow();

        $this->work();
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertNull($row->fresh()->provider_requested_at, 'The provider certainly did not accept it, so it may be retried.');
        $this->assertSame('1007', $row->fresh()->provider_reference);
        $this->assertSame(1, $this->jobCount(), 'The job is released for a retry.');

        $this->work();
        $this->assertSame(1, $row->fresh()->attempts, 'The retry waits for its backoff.');

        $this->travel(61)->seconds();
        $this->work();
        $this->travel(301)->seconds();
        $this->work();

        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->attempts);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(0, $this->jobCount());
    }

    public function test_retries_stop_after_five_failed_deliveries(): void
    {
        Log::spy();
        $this->sms->failUsing(fn () => throw SmsDeliveryException::retryable('SMS balance is insufficient.', '1007'));
        $row = $this->confirmedSmsRow();

        foreach ([0, 61, 301, 901, 1801] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->work();
        }

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(5, $row->fresh()->attempts);
        $this->assertSame(0, $this->jobCount());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame([], $this->sms->sent);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Appointment notification delivery gave up after all retries.'
            && $context['appointment_notification_id'] === $row->id)->once();
    }

    public function test_permanent_and_uncertain_outcomes_are_final_and_never_resent(): void
    {
        $outcomes = [
            'undelivered' => SmsDeliveryException::permanent('Sender ID is incorrect or disabled.', '1002'),
            'unconfirmed' => SmsDeliveryException::uncertain('SMS provider did not respond in time.', 'timeout'),
        ];

        foreach ($outcomes as $status => $exception) {
            $this->sms->failUsing(fn () => throw $exception);
            $row = $this->confirmedSmsRow();

            $this->work();
            $this->assertSame($status, $row->fresh()->status);
            $this->assertSame(0, $this->jobCount(), "{$status} is not retried.");

            $this->sms->failUsing(null);
            SendAppointmentNotification::dispatch($row->id);
            $this->work();
            $this->travel(31)->minutes();
            $this->artisan('mydoc:requeue-pending-notifications')->assertSuccessful();
            $this->work();

            $this->assertSame($status, $row->fresh()->status, 'A duplicate job or the requeue command never resends it.');
        }

        $this->assertSame([], $this->sms->sent);
    }

    public function test_an_unexpected_error_after_contacting_the_provider_is_not_retried(): void
    {
        $this->sms->failUsing(fn () => throw new RuntimeException('Unexpected bug'));
        $row = $this->confirmedSmsRow();

        $this->work();

        $this->assertSame('unconfirmed', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->provider_requested_at);
        $this->assertSame(0, $this->jobCount());
    }

    public function test_an_interrupted_send_after_contacting_the_provider_is_closed_not_resent(): void
    {
        $row = $this->confirmedSmsRow();
        $this->simulateCrashedAttempt($row, providerContacted: true);

        $this->work();
        $this->assertSame('sending', $row->fresh()->status, 'A fresh claim held elsewhere is left alone.');
        $this->assertSame(1, $this->jobCount(), 'This job waits until the claim is stale.');

        $this->travel(11)->minutes();
        $this->work();

        $this->assertSame('unconfirmed', $row->fresh()->status);
        $this->assertSame('Delivery was interrupted after the SMS provider was contacted.', $row->fresh()->last_error);
        $this->assertSame([], $this->sms->sent, 'Never re-sent: the provider may already have delivered it.');
        $this->assertSame(0, $this->jobCount());
    }

    public function test_an_interrupted_attempt_before_contacting_the_provider_is_recovered(): void
    {
        $row = $this->confirmedSmsRow();
        $this->simulateCrashedAttempt($row, providerContacted: false);

        $this->travel(11)->minutes();
        $this->work();

        $this->assertSame('sent', $row->fresh()->status);
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_duplicate_jobs_and_requeues_deliver_exactly_once(): void
    {
        $row = $this->confirmedSmsRow();
        SendAppointmentNotification::dispatch($row->id);
        SendAppointmentNotification::dispatch($row->id);

        $this->work();
        $this->work();
        $this->work();
        $this->artisan('mydoc:requeue-pending-notifications')->assertSuccessful();
        $this->work();

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame(1, AppointmentNotification::query()->where('channel', 'sms')->count());
    }

    public function test_the_ledger_rejects_a_second_row_for_the_same_sms_identity(): void
    {
        $row = $this->confirmedSmsRow();

        $this->expectException(UniqueConstraintViolationException::class);
        AppointmentNotification::query()->create($row->only(['appointment_id', 'channel', 'type', 'transition_key']) + ['status' => 'pending']);
    }

    public function test_requeue_recovers_an_sms_whose_job_was_lost(): void
    {
        $row = $this->confirmedSmsRow();
        DB::table('jobs')->delete();
        $this->assertSame('pending', $row->fresh()->status, 'The SMS row was committed but its job is gone.');

        $this->travel(31)->minutes();
        $this->artisan('mydoc:requeue-pending-notifications')->expectsOutputToContain('re-queued')->assertSuccessful();
        $this->work();
        $this->work();

        $this->assertSame('sent', $row->fresh()->status);
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_sms_failures_never_affect_the_email_and_logs_hold_no_phone_or_text(): void
    {
        Log::spy();
        $this->sms->failUsing(fn () => throw SmsDeliveryException::permanent('Sender ID is incorrect or disabled.', '1002'));
        $row = $this->confirmedSmsRow();

        $this->work();
        $this->work();

        $this->assertSame('undelivered', $row->fresh()->status);
        $this->assertSame('sent', AppointmentNotification::query()->where('channel', 'email')->sole()->status);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($row): bool {
            $encoded = json_encode($context);

            return $message === 'Appointment SMS delivery failed.'
                && $context['appointment_notification_id'] === $row->id
                && $context['outcome'] === SmsDeliveryException::PERMANENT
                && $context['provider_code'] === '1002'
                && ! str_contains($encoded, '1712345678')
                && ! str_contains($encoded, 'is confirmed');
        })->once();
    }

    public function test_bulksmsbd_request_format_and_success_response(): void
    {
        Http::preventStrayRequests();
        Http::fake(['bulksmsbd.net/*' => Http::response(['response_code' => 202, 'message_id' => 98765, 'success_message' => 'SMS Submitted Successfully', 'error_message' => ''])]);

        $result = $this->bulkSmsBd()->send('8801712345678', 'myDoc: Your appointment & time', 'mydoc-notification-7');

        $this->assertSame('98765', $result->providerReference);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://bulksmsbd.net/api/smsapi'
            && $request->isForm()
            && $request['api_key'] === 'test-key'
            && $request['type'] === 'text'
            && $request['number'] === '8801712345678'
            && $request['senderid'] === '8809601234567'
            && $request['message'] === 'myDoc: Your appointment & time');
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{0: mixed, 1: int, 2: string, 3: string|null}>
     */
    public static function providerResponses(): array
    {
        return [
            'bare success code' => ['202', 200, 'success', '202'],
            'insufficient balance' => [['response_code' => 1007], 200, SmsDeliveryException::RETRYABLE, '1007'],
            'ip not whitelisted' => [['response_code' => 1032], 200, SmsDeliveryException::RETRYABLE, '1032'],
            'internal error is uncertain' => [['response_code' => 1005], 200, SmsDeliveryException::UNCERTAIN, '1005'],
            'undocumented code is uncertain' => [['response_code' => 1099], 200, SmsDeliveryException::UNCERTAIN, '1099'],
            'undocumented success-like code is uncertain' => [['response_code' => 200], 200, SmsDeliveryException::UNCERTAIN, '200'],
            'balance validity expired' => [['response_code' => 1006], 200, SmsDeliveryException::RETRYABLE, '1006'],
            'incomplete request' => [['response_code' => 1003], 200, SmsDeliveryException::PERMANENT, '1003'],
            'account not verified' => [['response_code' => 1031], 200, SmsDeliveryException::PERMANENT, '1031'],
            'wrong sender id' => [['response_code' => 1002], 200, SmsDeliveryException::PERMANENT, '1002'],
            'masking needs bengali' => [['response_code' => 1012], 200, SmsDeliveryException::PERMANENT, '1012'],
            'account disabled' => [['response_code' => 1018], 200, SmsDeliveryException::PERMANENT, '1018'],
            'rate limited' => ['', 429, SmsDeliveryException::RETRYABLE, 'http-429'],
            'unavailable' => ['', 503, SmsDeliveryException::RETRYABLE, 'http-503'],
            'server error' => ['', 500, SmsDeliveryException::UNCERTAIN, 'http-500'],
            'unreadable success page' => ['<html>OK</html>', 200, SmsDeliveryException::UNCERTAIN, null],
        ];
    }

    #[DataProvider('providerResponses')]
    public function test_bulksmsbd_response_codes_are_classified_safely(mixed $body, int $status, string $expected, ?string $code): void
    {
        Http::preventStrayRequests();
        Http::fake(['bulksmsbd.net/*' => Http::response($body, $status)]);

        try {
            $result = $this->bulkSmsBd()->send('8801712345678', 'Hello', 'ref');
            $this->assertSame('success', $expected);
            $this->assertSame($code, $result->providerReference);
        } catch (SmsDeliveryException $exception) {
            $this->assertSame($expected, $exception->outcome);
            $this->assertSame($code, $exception->providerCode);
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            $this->assertStringNotContainsString('1712345678', $exception->getMessage());
        }
    }

    public function test_bulksmsbd_connection_failures_retry_only_when_nothing_reached_the_provider(): void
    {
        Http::preventStrayRequests();
        $cases = [
            'cURL error 7: Failed to connect to bulksmsbd.net port 443' => SmsDeliveryException::RETRYABLE,
            'cURL error 6: Could not resolve host: bulksmsbd.net' => SmsDeliveryException::RETRYABLE,
            'cURL error 28: Connection timed out after 5001 milliseconds' => SmsDeliveryException::RETRYABLE,
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' => SmsDeliveryException::UNCERTAIN,
            'cURL error 56: Recv failure: Connection reset by peer' => SmsDeliveryException::UNCERTAIN,
        ];

        foreach ($cases as $error => $expected) {
            $http = new HttpFactory;
            $http->fake(fn () => throw new ConnectionException($error));

            try {
                (new BulkSmsBdGateway($http, 'test-key', '8809601234567'))->send('8801712345678', 'Hello', 'ref');
                $this->fail('A connection failure must throw.');
            } catch (SmsDeliveryException $exception) {
                $this->assertSame($expected, $exception->outcome, $error);
            }
        }
    }

    public function test_bulksmsbd_without_credentials_is_not_configured_and_makes_no_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        foreach ([[null, '8809601234567'], ['test-key', null], ['', '']] as [$key, $sender]) {
            $gateway = new BulkSmsBdGateway(app(HttpFactory::class), $key, $sender);
            $this->assertFalse($gateway->isConfigured());

            try {
                $gateway->send('8801712345678', 'Hello', 'ref');
                $this->fail('An unconfigured provider must not send.');
            } catch (SmsDeliveryException $exception) {
                $this->assertSame(SmsDeliveryException::PERMANENT, $exception->outcome);
            }
        }

        Http::assertNothingSent();
    }

    private function bulkSmsBd(): BulkSmsBdGateway
    {
        return new BulkSmsBdGateway(app(HttpFactory::class), 'test-key', '8809601234567');
    }

    /**
     * A doctor confirms a booking: one email and one SMS to the patient are recorded and queued. The email job
     * (queued first) is delivered here, so each test starts with only the SMS job waiting.
     */
    private function confirmedSmsRow(): AppointmentNotification
    {
        $doctor = $this->smsDoctor(enabled: false);
        $patient = $this->smsPatient();
        $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3 + Appointment::count()));

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))->assertRedirect();
        DB::table('jobs')->where('payload', 'like', '%SyncAppointmentToGoogleCalendar%')->delete();
        $this->work();
        $this->assertSame('sent', AppointmentNotification::query()->where('channel', 'email')->where('appointment_id', $appointment->id)->sole()->status, 'The email is queued first and delivered independently.');

        return $this->smsLedger('confirmed')->firstWhere('appointment_id', $appointment->id);
    }

    private function simulateCrashedAttempt(AppointmentNotification $row, bool $providerContacted): void
    {
        $row->forceFill([
            'status' => 'sending',
            'attempts' => 1,
            'provider_requested_at' => $providerContacted ? now() : null,
        ])->save();
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--sleep' => 0]);
    }

    private function jobCount(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendAppointmentNotification%')->count();
    }
}
