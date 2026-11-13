# myDoc – Queue worker and appointment emails

myDoc sends appointment emails and Google Calendar updates through Laravel's queue. **Nothing is delivered
unless a queue worker is running.**

| Event | Recipient |
|---|---|
| Patient books an appointment | Doctor |
| Doctor confirms an appointment | Patient |
| Doctor rejects an appointment | Patient |
| Patient cancels an appointment | Doctor |
| Doctor reschedules an appointment | Patient |

Appointments that a doctor creates manually do not send a booking email; the patient is emailed when the doctor
confirms the appointment.

### Setup

1. Apply the migrations (this adds the `appointment_notifications` delivery ledger). Run this **before or
   together with** deploying the code: every booking, confirmation, rejection, cancellation and reschedule
   writes to this table, so those actions fail until it exists.

   ```bash
   php8.5 artisan migrate
   ```

2. Configure mail in `.env` (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
   `MAIL_FROM_ADDRESS`). With the default `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log`
   instead of being sent.

3. Set `APP_URL` to the public address of the site; links in emails are built from it.

### Running the worker

The project uses the `database` queue (`QUEUE_CONNECTION=database`; the `jobs` and `failed_jobs` tables are
created by the default migrations).

```bash
php8.5 artisan queue:work
```

During local development, run it in a separate terminal alongside the web server.

**Production must run a persistent worker** under a process monitor such as Supervisor or systemd, so it is
restarted if it stops. After every deployment, restart workers so they load the new code:

```bash
php8.5 artisan queue:restart
```

Use `QUEUE_CONNECTION=database` (or another real queue) in production, never `sync`: with `sync` an email failure
would surface to the user after the appointment was already saved, and could not be retried.

### Running the scheduler

A scheduled command, `mydoc:requeue-pending-notifications`, runs every 15 minutes. It re-queues emails that have
been `pending` for over 30 minutes without a delivery job (for example if the queue was unreachable right after an
appointment was saved). Production needs the standard Laravel scheduler cron entry:

```cron
* * * * * cd /path-to-myDoc && php8.5 artisan schedule:run >> /dev/null 2>&1
```

Check the schedule with `php8.5 artisan schedule:list`.

### Timeouts

| Setting | Value | Why |
|---|---|---|
| SMTP socket timeout (`MAIL_TIMEOUT`) | 20 s | A hung mail server fails the attempt instead of blocking the worker |
| Email job timeout | 45 s | Enough for the SMTP exchange and the ledger update |
| Database queue `retry_after` (`DB_QUEUE_RETRY_AFTER`) | 90 s | Must stay above the job timeout |
| Stale `sending` claim | 10 min | After this, an interrupted delivery can be taken over |

If you change any of these, keep the order: SMTP timeout < job timeout < `retry_after` < stale-claim window.

### Retries and failures

- A delivery failure (for example an SMTP error) is retried with increasing back-off (1, 5, 15 and 30 minutes).
  After **5 delivery failures** the job is failed.
- Every email is recorded once in `appointment_notifications` with its status (`pending`, `sending`, `sent`,
  `failed`, `skipped`). A row marked `sent` is never sent again, so retries cannot produce duplicate emails.
- If a worker crashes or times out mid-send, the queue redelivers the job; it waits until the interrupted
  attempt's claim is 10 minutes old and then completes the delivery. This waiting does not use up the five
  delivery attempts (an overall ceiling of 15 runs still bounds a job that keeps crashing).
- Emails to deactivated accounts, invalid addresses, or about appointments that changed while queued are
  `skipped`.
- Jobs that exhaust their retries appear in `php8.5 artisan queue:failed` and can be retried with
  `php8.5 artisan queue:retry all`.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
