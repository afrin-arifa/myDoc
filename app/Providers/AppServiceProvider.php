<?php

namespace App\Providers;

use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\DoctorWorkspace;
use App\Services\Sms\BulkSmsBdGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, function ($app): SmsGateway {
            $driver = (string) config('sms.default', 'none');

            // Automated tests must never reach a real provider.
            if ($app->runningUnitTests() && ! in_array($driver, ['none', 'log'], true)) {
                $driver = 'none';
            }

            return match ($driver) {
                'log' => new LogSmsGateway,
                'bulksmsbd' => new BulkSmsBdGateway(
                    $app->make(HttpFactory::class),
                    config('sms.drivers.bulksmsbd.api_key'),
                    config('sms.sender_id'),
                    (string) config('sms.drivers.bulksmsbd.base_url', 'https://bulksmsbd.net/api'),
                    (int) config('sms.timeout', 10),
                    (int) config('sms.connect_timeout', 5),
                ),
                default => new NullSmsGateway,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // `composer run dev` also runs the scheduler, so expiry, reminders and notification recovery work locally.
        DevCommands::artisan('schedule:work', 'scheduler');

        // Shared doctor workspace views build their links through DoctorWorkspace::url(), which points to the
        // doctor's own routes or to the assistant workspace of the selected doctor.
        View::share('workspace', $this->app->make(DoctorWorkspace::class));

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        // Patient self-registration: enough for a family sharing one connection, not for scripted sign-ups.
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(5)->by('registration:'.$request->ip()),
            Limit::perHour(20)->by('registration-hour:'.$request->ip()),
        ]);

        RateLimiter::for('doctor-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by('doctor-invitations:'.$request->ip()));
        RateLimiter::for('assistant-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by('assistant-invitations:'.$request->ip()));

        // {doctorProfile} is always bound to a real doctor profile (unknown ids are not found), even on reused doctor
        // controllers that do not type-hint it. Access to it is then checked by the works-for-doctor middleware.
        Route::model(DoctorWorkspace::ROUTE_PARAMETER, DoctorProfile::class);

        // {assistant} route parameters only ever resolve to Doctor Assistant accounts; any other id is not found.
        Route::bind('assistant', fn (string $value): User => User::query()->where('role', User::ROLE_ASSISTANT)->findOrFail($value));
    }
}
