<?php

namespace App\Providers;

use App\Services\Sms\BulkSmsBdGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-reset:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-reset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('doctor-invitations', fn (Request $request): Limit => Limit::perMinute(10)->by('doctor-invitations:'.$request->ip()));
    }
}
