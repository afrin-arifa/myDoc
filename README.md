# myDoc

Multi-doctor appointment booking for patients, doctors and administrators (Laravel 13, PHP 8.5, MySQL).

## Setup

1. Apply the migrations **before or together with** deploying the code:

   ```bash
   php8.5 artisan migrate
   ```

   This adds the `appointment_notifications` delivery ledger, the `doctor_invitation_tokens` table and the
   `qualifications` / `photo_path` columns on `doctor_profiles`. Every appointment change writes to the ledger,
   so those actions fail until it exists.

2. Configure `.env` (names only; never commit real values): `APP_URL` (links in emails are built from it),
   `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`,
   `MAIL_FROM_NAME`, optional `MAIL_TIMEOUT` (SMTP timeout, default 20 s), `QUEUE_CONNECTION=database`.
   With `MAIL_MAILER=log`, emails, **including invitation and password-reset links**, are written to
   `storage/logs/laravel.log`; use it only for local development.

3. Run a queue worker and the scheduler (see below), and create the first administrator with
   `php8.5 artisan mydoc:admin`.

## Doctor onboarding (admin)

1. **Admin → Doctors → Add doctor.** Enter the doctor's name, email, phone and professional details. There is
   no password field: the account is created with a random, unknown password, the `doctor` role is assigned on
   the server, and the account starts **deactivated** (it cannot sign in and is not listed publicly).
2. An **invitation email** is sent immediately. Its link is valid for **72 hours** and works **once**. Only a
   hash of the token is stored (`doctor_invitation_tokens`, managed by Laravel's password-broker token
   repository); the raw token exists only in the email.
3. The doctor opens the link, chooses a password (8+ characters with upper- and lower-case letters and a
   number) and the account becomes **active**. The token is deleted, the email is marked verified, and a
   queued confirmation email is sent.
4. The doctor signs in and completes **Profile**: biography, qualifications, specialties, consultation fee and
   photo (JPG, PNG or WebP, up to 2 MB). Photos are stored on the private disk and served through
   `/doctors/{id}/photo`, which only shows photos of publicly listed doctors to other users.

Account status on the admin doctor pages (searchable and filterable):

| Status | Meaning | Admin actions |
|---|---|---|
| Invitation pending | Invited, link still valid | Resend invitation (replaces the old link; at most once a minute), cancel invitation |
| Invitation expired | Invited, link expired | Resend invitation, cancel invitation |
| Active | Doctor can sign in and is listed if accepting patients | Deactivate |
| Deactivated | Cannot sign in, use any doctor page, or receive bookings; hidden from patients | Activate |

There is a single status mechanism, `users.deactivated_at`; the invitation state is derived from the invitation
token. Deactivating keeps all appointments, schedules and Google Calendar records. An account still waiting
for its invitation cannot be activated by an admin, so no account is ever active with a password nobody knows.
Only administrators can create doctors or change account status.

The general **Admin → Users** page follows the same rules: it cannot assign or remove the `doctor` role
(doctors are created only through **Add doctor**), cannot activate a doctor who has not accepted their
invitation, and routes doctor activation/deactivation through the onboarding service. Changing the email of a
doctor with a pending invitation (on either page) destroys the old invitation and sends a new one to the new
address.

If an invitation email cannot be sent, the account is still created; use **Resend invitation**. Invitation and
password-reset emails are sent immediately instead of being queued so that raw tokens are never stored in the
`jobs` table.

## Password reset

Every active patient, doctor and administrator can use **Forgot password?** on the login page. It uses
Laravel's password broker: hashed tokens in `password_reset_tokens`, valid for **60 minutes**, single use, and at
most one email per account per minute. Requests are rate limited (5 per minute per email and IP, 20 per minute
per IP). Sessions are bound to the password (Laravel's `AuthenticateSession` middleware): after a reset or a
password change, every other signed-in session and remember-me cookie of that user is signed out on its next
request; the session that changed the password stays signed in. The response is always the same, whether or not the email belongs to an account; deactivated accounts
(including doctors still awaiting their invitation) never receive reset emails.

## Doctor data isolation

Each doctor has a private workspace:

- Dashboard statistics, appointment lists, schedule, availability, slot generation, patient search and Google
  Calendar settings are always scoped to the signed-in doctor's own profile.
- Every appointment action is authorized by `AppointmentPolicy` against the stored slot owner, and the services
  re-check ownership inside their transactions. Availability is authorized by `DoctorAvailabilityPolicy`, and
  profiles and accounts by `DoctorProfilePolicy`.
- IDs submitted in URLs or forms never grant access: requests for another doctor's records return 403, and forged
  `doctor_profile_id` / `user_id` fields are ignored.
- Patient search and the **Patients** page (`/doctor/patients`) only include patients who have at least one
  appointment with that doctor. Each patient is listed once, with appointment counts and the last appointment
  date counted for that doctor only; a first-time patient can be booked only by exact email.
- Doctors can **block** a free future slot and **unblock** it from the schedule calendar. Slots with a booked or
  confirmed appointment, past slots and other doctors' slots cannot be changed. Blocked slots cannot be booked,
  are kept by slot generation, and are shown on the calendar with their own filter.
- The public directory shows only public professional information of active, accepting doctors.
- Administrators have platform-wide access.

## Medical records

- **Patients** upload documents for themselves (prescriptions, laboratory, diagnostic and imaging reports,
  medical certificates, other) from **Medical records** or the dashboard, optionally linked to one of their own
  appointments, and can download or delete them.
- **Doctors** see and download (never upload or delete) documents of patients who have at least one **booked,
  confirmed or completed** appointment with them. A cancelled or rejected booking alone does not grant access, and
  a document's appointment link never grants access by itself. Links to another doctor's appointment are hidden.
- **Administrators** can search, filter, inspect, download and delete every document; each document's detail page
  shows its activity (uploads, downloads, deletions) from `medical_document_events`.

Files are stored on the private `local` disk (`storage/app/private/medical-documents/{patient}/`) under random
names and are only served by the authorized download route as attachments with `nosniff`, `no-store` and a
restrictive Content-Security-Policy. Accepted: PDF, JPG, PNG and WebP up to 10 MB, verified by the file's actual
content and a matching extension.

**PHP upload limits:** the PHP CLI configuration here allows only 2 MB uploads (`upload_max_filesize=2M`,
`post_max_size=8M`). To accept 10 MB documents, raise them in the PHP configuration used by the web server, e.g.
`upload_max_filesize = 12M` and `post_max_size = 16M`, and allow at least that request size in the web server.
Until then the upload form shows the lower effective limit and rejects larger files in the browser before upload.
`php artisan serve` cannot pass these settings, so for local development either edit the CLI `php.ini` or start
the server with them:

```bash
cd public && php -d upload_max_filesize=12M -d post_max_size=16M -S 127.0.0.1:8000 \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

## Appointment emails and queue

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

## Appointment SMS

Opted-in patients and doctors receive short appointment SMS in addition to (never instead of) the emails above.
SMS reuses the same ledger (`appointment_notifications`, now with a `channel` column), the same
`SendAppointmentNotification` job, its atomic claim, retries and the `mydoc:requeue-pending-notifications` recovery.

| Event | SMS to |
|---|---|
| Patient books | patient (booking received) and doctor (new request) |
| Doctor books for a patient | patient |
| Doctor confirms / rejects / reschedules | patient |
| Patient cancels | doctor |
| Reminder 24 h and 2 h before (booked or confirmed only) | patient |

* **Opt-in:** every user starts opted out (`users.sms_notifications_enabled = false`). Patients enable SMS on
  *My profile*, doctors on *Account settings*; enabling requires a Bangladeshi mobile number. Email is unaffected.
* **Phone numbers:** forms accept `01XXXXXXXXX`, `+8801XXXXXXXXX`, `8801XXXXXXXXX`, `008801XXXXXXXXX` (spaces/dashes
  ignored) and store `8801XXXXXXXXX`. Older free-text numbers are kept as they are; an SMS is skipped when the
  current number is not a valid mobile number.
* **Content:** reference, doctor name, date and time only. No notes, reasons, symptoms, documents or links.
* **Reminders:** `mydoc:queue-appointment-reminders` runs every five minutes from the scheduler. A reminder is
  queued once per slot and start time, only if the appointment was booked before the reminder was due, and is
  re-checked before sending, so cancelled, rejected, completed or rescheduled appointments never get outdated ones.
* **No duplicates:** BulkSMSBD has no idempotency key, so an SMS whose provider request may have gone out (timeout,
  unreadable response, provider internal error 1005, any undocumented code, crashed worker) is marked `unconfirmed`
  and never re-sent. Only failures that confirm the provider did not accept the SMS (connection refused, HTTP 429 or
  503, balance and IP-whitelist codes 1006, 1007, 1032) are retried; configuration errors (wrong sender id, disabled
  account, codes 1002, 1003, 1011–1031) are `undelivered`. Admins review all three under *SMS issues*.

### Provider setup (BulkSMSBD)

1. Create a BulkSMSBD account, buy an SMS package, and note the **API key** and the **approved sender id** from the
   panel (the non-masking number, or a registered masking name such as `myDoc`). BulkSMSBD requires masking SMS to
   be written in Bengali (error 1012); these messages are English, so use a non-masking sender id for now.
2. If the panel enforces IP whitelisting (error 1032), whitelist the server's public IP address.
3. Set in `.env` (never commit real values):
   ```dotenv
   SMS_DRIVER=bulksmsbd
   SMS_SENDER_ID=your-approved-sender-id
   BULKSMSBD_API_KEY=your-api-key
   ```
4. Clear the config cache (`php8.5 artisan config:clear`), keep `queue:work` and the scheduler running.

Without these settings (`SMS_DRIVER=none`, the default) nothing is sent and SMS ledger rows are marked `skipped`.
`SMS_DRIVER=log` writes a redacted line to the log instead of sending, for development. Tests always use a fake.

## Testing

Tests run against an in-memory SQLite database (see `phpunit.xml`) and never touch the MySQL database. Use
PHP 8.5, which has the SQLite driver:

```bash
php8.5 artisan test
php8.5 artisan test --filter=DoctorDataIsolationTest
php8.5 vendor/bin/pint --test
```

## Known limitations

- PHP 8.5 here has no GD extension, so uploaded photos are validated (type, size, dimensions) but not re-encoded.
- Pages have been checked through automated HTTP rendering tests, not in a real browser.

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
