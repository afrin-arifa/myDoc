# myDoc

Multi-doctor appointment booking for patients, doctors, Doctor Assistants and administrators (Laravel 13,
PHP 8.5, MySQL 8, Bootstrap 5.3, Blade and Vite).

## Setup

1. Apply the migrations **before or together with** deploying the code. Preview them first, then apply:

   ```bash
   php8.5 artisan migrate --pretend
   php8.5 artisan migrate
   ```

   The migrations are additive. Besides the base tables they create the `appointment_notifications` delivery
   ledger, doctor and assistant invitation tokens, the `assistant` role with `doctor_assistant` assignments and
   their history, the `doctor_workspace_events` audit log and `appointments.cancellation_reason`. Appointment
   actions write to the ledger and the audit log in the same transaction, so they fail until the tables exist.
   Never run `migrate:fresh`, `migrate:refresh` or `db:wipe` against a database with real data. Check what is
   pending with `php8.5 artisan migrate:status`.

2. Configure `.env` (names only; never commit real values): `APP_URL` (links in emails are built from it),
   `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`,
   `MAIL_FROM_NAME`, optional `MAIL_TIMEOUT` (SMTP timeout, default 20 s), `QUEUE_CONNECTION=database`.
   With `MAIL_MAILER=log`, emails, **including invitation and password-reset links**, are written to
   `storage/logs/laravel.log`; use it only for local development.

3. Run a queue worker and the scheduler (see below), and create the first administrator with
   `php8.5 artisan mydoc:admin`.

### Running locally

```bash
composer install && npm install
npm run build              # or `npm run dev` while editing views and assets
php8.5 artisan migrate
php8.5 artisan serve       # http://127.0.0.1:8000
php8.5 artisan queue:work  # second terminal: emails, SMS and Google Calendar updates
php8.5 artisan schedule:work  # third terminal: reminders and notification recovery
```

`composer run dev` starts the server, queue worker and Vite together. `php8.5 artisan db:seed --class=DemoUsersSeeder`
creates demo accounts for local use only.

## Roles

| Role | Signs up | Area | Can do |
|---|---|---|---|
| Patient | Self-registration | `/patient/...` | Browse doctors, book and cancel own appointments, upload own medical documents, manage profile and SMS preference |
| Doctor | Admin invitation | `/doctor/...` | Own profile, schedule, slots, appointments (confirm, reject, reschedule, cancel, start, complete), patients and their eligible documents, Google Calendar |
| Doctor Assistant | Admin invitation | `/assistant/...` | Works in the workspace of each doctor they are assigned to (see below); own account and password |
| Admin | `mydoc:admin` | `/admin/...` | Users, doctors, assistants and assignments, specialties, all appointments and documents, SMS issues |

**There is no guest access.** Without a session only these pages work: log in, patient registration, forgot and
reset password, and doctor or assistant invitation acceptance (token-checked, expiring, single use). Every other
URL, including `/`, the doctor directory, doctor profiles, their available slots and doctor photos, redirects to
the login page. A signed-in user who opens `/` is sent to their role's dashboard, and logging out returns to the
login page.

Every role area is protected by the `role:` middleware, which also refuses deactivated accounts on every request.
Navigation only shows each role its own links, but hiding a link is never the access check: routes, policies,
Form Requests and services authorize every request again.

## Doctor onboarding (admin)

1. **Admin → Doctors → Add doctor.** Enter the doctor's name, email, phone and professional details. There is
   no password field: the account is created with a random, unknown password, the `doctor` role is assigned on
   the server, and the account starts **deactivated** (it cannot sign in and is not listed in the directory).
2. An **invitation email** is sent immediately. Its link is valid for **72 hours** and works **once**. Only a
   hash of the token is stored (`doctor_invitation_tokens`, managed by Laravel's password-broker token
   repository); the raw token exists only in the email.
3. The doctor opens the link, chooses a password (8+ characters with upper- and lower-case letters and a
   number) and the account becomes **active**. The token is deleted, the email is marked verified, and a
   queued confirmation email is sent.
4. The doctor signs in and completes **Profile**: biography, qualifications, specialties, consultation fee and
   photo (JPG, PNG or WebP, up to 2 MB). Photos are stored on the private disk and served through
   `/doctors/{id}/photo` (signed-in users only), which shows photos of listed doctors only to users who may browse
   the directory, and otherwise only to the doctor, their assigned assistants and admins.

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

## Password reset and password change

Every active patient, doctor, Doctor Assistant and administrator can use **Forgot password?** on the login page. It uses
Laravel's password broker: hashed tokens in `password_reset_tokens`, valid for **60 minutes**, single use, and at
most one email per account per minute. Requests are rate limited (5 per minute per email and IP, 20 per minute
per IP). Sessions are bound to the password (Laravel's `AuthenticateSession` middleware): after a reset or a
password change, every other signed-in session and remember-me cookie of that user is signed out on its next
request; the session that changed the password stays signed in. The response is always the same, whether or not the email belongs to an account; deactivated accounts
(including doctors still awaiting their invitation) never receive reset emails.

Signed-in users change their password with the shared **Change password** form (patient *My profile*, doctor
*Account settings*, assistant *My account*), which posts to `PUT /profile/password`. It requires the current
password, updates only the signed-in user's own account and refuses deactivated accounts. Staff accounts (doctors,
assistants, admins) must keep the invitation strength: 8+ characters with upper- and lower-case letters and a
number. Patients keep the registration rule (8+ characters).

## Doctor Assistants

**Invitation and onboarding.** *Admin dashboard → Doctor assistants → Invite assistant* creates the account with the `assistant`
role set on the server, a random unknown password and a deactivated status, and emails an invitation (separate
from doctor invitations: `assistant_invitation_tokens`, hashed, single use, valid 72 hours, resend at most once a
minute). The assistant sets a password from the link, which activates the account, and then signs in normally.
Admins can resend a pending or expired invitation, edit the assistant's details, and deactivate or reactivate them.

**Assignment.** On the assistant's admin page an admin assigns them to one or more doctors and removes
assignments. Each change is recorded in the assignment history with the admin as actor. Deactivating an assistant
removes all their assignments, so reactivation never restores earlier access; the admin assigns doctors again.

**Workspace.** The assistant's dashboard lists the doctors they currently work for. Each doctor's workspace is at
`/assistant/doctors/{doctor}/...` and reuses the doctor pages, worded for the assistant ("Appointments for
Dr X", "Patients of Dr X") and always showing *Working for Dr X* with a **Switch doctor** control. Access is
re-checked on every request (`works-for-doctor` middleware and `DoctorAccess`): the assignment must exist and
both the assistant and the doctor must be active. An unassigned doctor returns 403, and a record of another
doctor reached through a workspace URL is refused (403 or 404).

**Permissions** (`DoctorAccess::ASSISTANT_ABILITIES`):

| Assistants can | Assistants cannot |
|---|---|
| View appointments; book for patients; confirm, reject, reschedule | Start or complete consultations |
| Cancel booked or confirmed appointments (reason required) | Edit the doctor's profile, account or Google Calendar |
| Manage availability, generate, block and unblock slots | Upload or delete patient documents |
| View patients and eligible medical documents of the selected doctor; preview and download them in that workspace | Use doctor, patient or admin pages; change their own role, status or assignments |

Every action is recorded under the assistant's own user id, never as the doctor. *My account* shows the
assistant's details and assigned doctors read-only and lets them change their password; name, email, phone and
assignments are changed by an admin.

## Appointment cancellation

- **Patients** cancel their own booked or confirmed appointments under the existing patient rules on their
  appointment page. The doctor is notified.
- **Doctors and their assigned assistants** can cancel any booked or confirmed appointment of that doctor at any
  time (no 24-hour limit) from the appointment page. A **reason is required** (1–1000 characters, whitespace
  only is rejected). Completed, rejected and already cancelled appointments cannot be cancelled. Admins do not
  cancel appointments.
- **Reason handling:** the reason is stored only in `appointments.cancellation_reason` and shown only on the
  staff appointment page as "staff only". It is never sent to the patient (email or SMS), never written to the
  audit log and never shown on patient pages. Staff should not record clinical detail there.
- **Slot release:** in the same transaction the slot becomes available again only if it belongs to that doctor
  and is still `booked`; a blocked slot stays blocked. A unique "one active appointment per slot" index prevents
  double booking when the slot is rebooked. A second or concurrent cancellation is refused after the status is
  re-checked under a row lock, with no second notification or audit event.

## Workspace audit events

`doctor_workspace_events` is an append-only log of actions in a doctor's workspace: `booked`, `confirmed`,
`rejected`, `rescheduled`, `cancelled`, `started`, `completed`, `slot_blocked`, `slot_unblocked`,
`slots_generated`, `availability_created`, `availability_updated` and `availability_deleted`. Each event stores the
real actor (doctor, assistant or patient, with the role read from the database at that moment), the doctor, the
record, the status change and a small allow-listed context (for example `source`, `slot_id`, `starts_at`,
`slot_released`). Events are written inside the operation's transaction, so a failed action leaves none. Notes,
reasons, names, contact details and documents are never stored in the context. There is no admin viewer yet.

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
- The doctor directory (signed-in, active patients and admins only) shows the professional information of active,
  accepting doctors.
- Administrators have platform-wide access.

## Medical records

- **Patients** upload documents for themselves (prescriptions, laboratory, diagnostic and imaging reports,
  medical certificates, other) from **Medical records** or the dashboard, optionally linked to one of their own
  appointments, and can download or delete them.
- **Doctors** see and download (never upload or delete) documents of patients who have at least one **booked,
  confirmed or completed** appointment with them. A cancelled or rejected booking alone does not grant access, and
  a document's appointment link never grants access by itself. Links to another doctor's appointment are hidden.
- **Doctor Assistants** have the same read-only access, but only **inside one doctor's workspace**: they preview
  and download through `/assistant/doctors/{doctor}/medical-documents/{document}/download|preview`, and the patient
  must be in the care of *that* doctor. The doctor comes only from the URL path, and the assignment and both accounts
  are re-checked on every request. The shared `/medical-documents/...` routes refuse assistants, so changing a
  document id cannot reach patients of another assigned doctor. Downloads are audited under the assistant's own id.
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
| Doctor or assistant cancels an appointment | Patient ("cancelled by the clinic", with a link to book again) and doctor ("you cancelled" / "your assistant cancelled") |
| Doctor or assistant reschedules an appointment | Patient |

Confirm, reject and reschedule emails are the same whether the doctor or their assistant acted. Appointments
that a doctor or assistant creates manually do not send a booking email; the patient is emailed when the
appointment is confirmed. Assistants never receive appointment notifications, and the cancellation reason is
never included in any message.

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
| Doctor or assistant cancels | patient and doctor (who cancelled, never the reason) |
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
npm run build
```

Tests fake the queue, mail and SMS gateway where needed, so they never send real emails or SMS. Role and
workspace access is covered by `RoleAccessMatrixTest`, `NavigationTest`, `AssistantWorkspaceTest`,
`DoctorAccessAuthorizationTest` and `StaffAppointmentCancellationTest`.

## Known limitations

- PHP 8.5 here has no GD extension, so uploaded photos are validated (type, size, dimensions) but not re-encoded.
- Pages are covered by automated HTTP rendering tests; the main doctor and assistant pages were also checked
  in headless Chrome at desktop and phone widths. A full manual cross-browser pass has not been done.
- Admins have no password-change page; they use **Forgot password?**.

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
