<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\AppointmentNotification;
use App\Services\Sms\BulkSmsBdGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

/**
 * End to end through the queue worker with the real BulkSMSBD driver against a faked HTTP API (no network): each
 * provider outcome must leave the ledger in a consistent state and never cause a second provider request unless
 * the provider confirmed the first one was not accepted.
 */
class BulkSmsBdDeliveryOutcomeTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['queue.default' => 'database']);
        $this->app->instance(SmsGateway::class, new BulkSmsBdGateway(app(HttpFactory::class), 'test-key', '8809601234567'));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function uncertainCodes(): array
    {
        return [
            'provider internal error 1005' => [1005, 'Provider reported an internal error; delivery is unknown.'],
            'undocumented code' => [1099, 'Provider returned an undocumented response code; delivery is unknown.'],
        ];
    }

    #[DataProvider('uncertainCodes')]
    public function test_uncertain_codes_are_unconfirmed_and_never_sent_again(int $code, string $reason): void
    {
        Http::fake(['bulksmsbd.net/*' => Http::response(['response_code' => $code])]);
        $row = $this->queuedSmsRow();

        $this->work();

        $row->refresh();
        $this->assertSame(AppointmentNotification::STATUS_UNCONFIRMED, $row->status);
        $this->assertSame((string) $code, $row->provider_reference);
        $this->assertSame($reason, $row->last_error);
        $this->assertNotNull($row->provider_requested_at, 'The provider request stays recorded.');
        $this->assertNull($row->sent_at);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(0, $this->smsJobCount(), 'Not released for a retry.');

        $this->attemptRedelivery($row);

        $this->assertSame(AppointmentNotification::STATUS_UNCONFIRMED, $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function retryableCodes(): array
    {
        return [
            'balance validity 1006' => [1006],
            'insufficient balance 1007' => [1007],
            'ip not whitelisted 1032' => [1032],
        ];
    }

    #[DataProvider('retryableCodes')]
    public function test_confirmed_temporary_failures_are_retried_and_then_delivered_once(int $code): void
    {
        Http::fakeSequence('bulksmsbd.net/*')
            ->push(['response_code' => $code])
            ->push(['response_code' => 202, 'message_id' => 555]);
        $row = $this->queuedSmsRow();

        $this->work();
        $this->assertSame(AppointmentNotification::STATUS_FAILED, $row->fresh()->status);
        $this->assertNull($row->fresh()->provider_requested_at, 'The provider confirmed it was not accepted.');
        $this->assertSame((string) $code, $row->fresh()->provider_reference);
        $this->assertSame(1, $this->smsJobCount());

        $this->travel(61)->seconds();
        $this->work();

        $this->assertSame(AppointmentNotification::STATUS_SENT, $row->fresh()->status);
        $this->assertSame('555', $row->fresh()->provider_reference);
        $this->assertSame(2, $row->fresh()->attempts);
        $this->assertSame(0, $this->smsJobCount());
        Http::assertSentCount(2);
    }

    public function test_rate_limits_and_unavailability_are_retried(): void
    {
        Http::fakeSequence('bulksmsbd.net/*')->pushStatus(429)->pushStatus(503)->push(['response_code' => 202]);
        $row = $this->queuedSmsRow();

        $this->work();
        $this->travel(61)->seconds();
        $this->work();
        $this->travel(301)->seconds();
        $this->work();

        $this->assertSame(AppointmentNotification::STATUS_SENT, $row->fresh()->status);
        Http::assertSentCount(3);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function configurationCodes(): array
    {
        return [
            'wrong sender id 1002' => [1002],
            'masking must be bengali 1012' => [1012],
            'account disabled 1018' => [1018],
        ];
    }

    #[DataProvider('configurationCodes')]
    public function test_configuration_errors_are_undelivered_and_not_retried(int $code): void
    {
        Http::fake(['bulksmsbd.net/*' => Http::response(['response_code' => $code])]);
        $row = $this->queuedSmsRow();

        $this->work();
        $this->attemptRedelivery($row);

        $this->assertSame(AppointmentNotification::STATUS_UNDELIVERED, $row->fresh()->status);
        $this->assertSame((string) $code, $row->fresh()->provider_reference);
        $this->assertSame(1, $row->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_a_timeout_after_sending_is_unconfirmed_and_never_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received'));
        $row = $this->queuedSmsRow();

        $this->work();
        $this->attemptRedelivery($row);

        $this->assertSame(AppointmentNotification::STATUS_UNCONFIRMED, $row->fresh()->status);
        $this->assertSame('timeout', $row->fresh()->provider_reference);
        $this->assertSame(1, $row->fresh()->attempts);
    }

    /**
     * A duplicate job, a requeue sweep and a manual retry of the same notification must all leave a final row alone.
     */
    private function attemptRedelivery(AppointmentNotification $row): void
    {
        SendAppointmentNotification::dispatch($row->id);
        $this->work();
        $this->travel(31)->minutes();
        $this->artisan('mydoc:requeue-pending-notifications')->assertSuccessful();
        $this->work();
        (new SendAppointmentNotification($row->id))->handle();
    }

    /**
     * An opted-in patient's booking confirmation SMS, recorded by a doctor confirming the appointment. The email
     * (queued first, to the same patient) is delivered here so each test starts with only the SMS job waiting.
     */
    private function queuedSmsRow(): AppointmentNotification
    {
        $doctor = $this->smsDoctor(enabled: false);
        $patient = $this->smsPatient();
        $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3));

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))->assertRedirect();
        DB::table('jobs')->where('payload', 'like', '%SyncAppointmentToGoogleCalendar%')->delete();
        $this->work();

        return $this->smsLedger('confirmed')->sole();
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--sleep' => 0]);
    }

    private function smsJobCount(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendAppointmentNotification%')->count();
    }
}
