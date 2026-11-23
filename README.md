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
| Admin | `mydoc:admin` | `/admin/...` | Users, doctors, assistants and assignments, specialties, all appointments and documents, notification delivery issues |

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

## Practice locations (chambers)

A doctor can see patients at several locations. Doctors and their assigned assistants manage them in
*Schedule → Locations* (name, address, area, phone, directions).

- **Weekly availability is held at a location.** Each new window must use one of the doctor's own active locations;
  the service re-checks this under the doctor's schedule lock. Windows still cannot overlap across locations (a doctor
  cannot be in two chambers at once); back-to-back windows at different locations are allowed.
- **Slots keep their location.** Generated slots copy the window's location and never change it, so a booked
  appointment always shows where it was booked. Moving a window to another location blocks the open future slots
  generated for the old one; booked, confirmed and past slots are untouched. Rescheduling moves an appointment to the
  new slot's location.
- **Patients see the location before booking:** on the doctor's profile (list of locations, times grouped by location,
  and a location filter when there are several) and on the confirmation page (name, address, phone, directions).
  Appointment pages for patients, doctors, assistants and admins, the timetable, emails (including previous and new
  location on reschedule), patient SMS (location name, up to 30 characters) and the doctor's Google Calendar event
  show it too.
- **Editing and archiving:** edits apply everywhere, including past appointments. A location used by any window or
  slot cannot be deleted, only archived (hidden from new schedules), and archiving is refused while an active window
  uses it. Audit events record only the location id and which fields changed, never names or addresses.
- **Existing data:** slots and windows created before locations existed show "Location not specified". The
  `backfill_main_chamber_doctor_locations` migration gives every existing doctor a "Main chamber" (address copied
  from their account address if set) and links their existing windows and slots to it, changing nothing else.

## Follow-up appointments

A follow-up links to the patient's **completed** appointment with the **same doctor** (`appointments.follow_up_of_id`).

- **Patients** use *Book a follow-up* on a completed appointment: the doctor's profile and the confirmation page show
  which appointment is being followed up. **Doctors and assigned assistants** use *Book follow-up* on the completed
  appointment's page, which opens the booking form for that patient.
- The booking service checks the link inside the booking transaction, under the patient lock: the original must be
  this patient's completed appointment with the slot's doctor, and it may have at most one pending or confirmed
  follow-up at a time (a cancelled, rejected or expired one frees it). A forged or ineligible link is refused, never
  ignored. Follow-ups obey every normal rule: open future slot, the active-appointment limit, no overlaps, cancellation
  rules and expiry.
- The link never changes (rescheduling and cancellation keep it). A completed follow-up can itself be followed up;
  each stores its direct original. Appointment pages and cards for patients, staff and admins show "Follow-up to" and
  the follow-ups booked from an appointment; emails include the original's reference only.

## Consultation fees

- Each doctor has a **consultation fee** and an optional **follow-up fee** (never more than the consultation fee;
  empty means follow-ups cost the consultation fee). Both are set by the doctor (*Profile*) or an admin, from 0 to
  99,999,999.99 with at most two decimals. Fees are shown as `BDT 1,500.00`.
- **Every new appointment stores the fee that applied when it was created** (`appointments.consultation_fee` and
  `fee_type`: `standard` or `follow_up`), for patient and staff bookings alike, read from the database inside the
  booking transaction; a submitted fee is ignored. It is never recalculated: fee changes, rescheduling, cancellation
  and completion leave it unchanged. The confirmation page shows the fee that will be stored.
- Appointment pages for patients, staff and admins and the appointment emails show the stored fee. Appointments from
  before fees were stored show "Not recorded"; the backfill filled in only those whose doctor profile had not been
  edited since booking (so the fee was provable). Payment is not part of Release 1.

## Waiting list

When no time suits them, a patient can **join a doctor's waiting list** from the doctor's profile: a date range of up
to 14 days, optionally a time of day (morning, afternoon, evening), a location and, for follow-ups, the original
appointment. Limits: 3 open entries per patient, one per doctor. Their entries are under *Waiting list*.

- **Offers.** When a matching time becomes available (a cancellation, rejection, expiry, reschedule, unblock or newly
  generated slot), it is **held** for the first waiting patient who can take it (not at the 3-appointment limit, no
  overlapping appointment, active account), first come first served. The slot's status becomes `held`, so nobody
  else can book or block it, and the patient gets an email and, if opted in, an SMS. The hold lasts
  `WAITLIST_HOLD_MINUTES` (45) but always ends `WAITLIST_CUTOFF_MINUTES` (30) before the slot starts; a slot that
  cannot be held for at least `WAITLIST_MIN_OFFER_MINUTES` (15) stays open for normal booking.
- **Answering.** *Accept* books the held slot through the normal booking service (a pending request the doctor
  confirms; every rule applies, including the fee snapshot and follow-up link). *Decline* or letting it expire offers
  the time to the next patient; the same time is never offered twice to the same entry. After 2 unanswered offers an
  entry is paused until the patient resumes it. Entries end when booked, left, or when their dates pass.
- **Staff** (doctor or assigned assistant) use *Schedule → Waiting list*: patients in order, current offers (with
  *Withdraw*), *Offer this time* for a matching open slot, and the on/off switch (on by default; turning it off
  withdraws open offers and stops new requests). Held slots are purple on the timetable. **Admins** have a read-only
  overview (*Waiting lists*); offer emails and SMS appear under *Notification delivery issues*.
- **Safety.** Holding, accepting, declining, withdrawing and expiring lock the slot, then the offer, then the entry;
  a unique index allows only one pending offer per slot; accepting re-checks that the offer is still open and the slot
  still held for that patient. Offer messages contain the doctor, time, location, fee and hold time only. Audit events:
  `waitlist_offered`, `waitlist_declined`, `waitlist_offer_expired`, `waitlist_withdrawn`, `waitlist_settings`.

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
| Patient books an appointment | Doctor ("new appointment request") and patient ("request received") |
| Doctor or assistant books for a patient | Patient ("an appointment was booked for you") |
| Doctor confirms an appointment | Patient |
| Doctor rejects an appointment | Patient |
| Patient cancels an appointment | Doctor and patient (a cancellation receipt) |
| Doctor or assistant cancels an appointment | Patient ("cancelled by the clinic", with a link to book again) and doctor ("you cancelled" / "your assistant cancelled") |
| Doctor or assistant reschedules an appointment | Patient |
| A pending request expires (not confirmed before its start time) | Patient |

Confirm, reject and reschedule emails are the same whether the doctor or their assistant acted. Assistants never
receive appointment notifications, and the cancellation reason is never included in any message. Emails contain
scheduling details only (reference, doctor, patient name, date, time, consultation type): never notes, documents
or medical information.

### Running the worker

The project uses the `database` queue (`QUEUE_CONNECTION=database`; the `jobs` and `failed_jobs` tables are
created by the default migrations).

```bash
php8.5 artisan queue:work
```

During local development, run it in a separate terminal alongside the web server, or use `composer run dev`, which
starts the server, a queue listener, the scheduler (`schedule:work`), the log viewer and Vite together.

**Production must run a persistent worker** under a process monitor such as Supervisor or systemd, so it is
restarted if it stops. Each job sets its own tries and timeout (email/SMS 45 s, Google Calendar 60 s), so the worker
needs no `--tries` or `--timeout`. Example Supervisor program (`stopwaitsecs` lets a running job finish on restart):

```ini
[program:mydoc-worker]
command=php8.5 /path-to-myDoc/artisan queue:work database --sleep=3 --max-time=3600
directory=/path-to-myDoc
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=75
redirect_stderr=true
stdout_logfile=/path-to-myDoc/storage/logs/worker.log
```

After every deployment, restart workers so they load the new code:

```bash
php8.5 artisan queue:restart
```

Use `QUEUE_CONNECTION=database` (or another real queue) in production, never `sync`: with `sync` an email failure
would surface to the user after the appointment was already saved, and could not be retried.

### Running the scheduler

| Command | Runs | Purpose |
|---|---|---|
| `mydoc:expire-pending-appointments` | every minute | Expires pending requests whose start time has passed and notifies the patient |
| `mydoc:queue-appointment-reminders` | every 5 minutes | Queues the 24 h and 2 h reminder SMS for confirmed appointments |
| `mydoc:requeue-pending-notifications` | every 15 minutes | Re-queues emails and SMS `pending` for over 30 minutes without a delivery job |
| `mydoc:reconcile-google-calendar` | hourly | Re-syncs failed Google Calendar events and removes stale ones |
| `mydoc:process-waitlist` | every minute | Ends waiting list offers whose hold ran out (offering the time to the next patient), ends past entries, offers open times |

Every task runs on one server only and never overlaps itself. Their overlap locks expire after 10, 15, 30 and 55
minutes (not Laravel's default 24 hours), so a scheduler killed mid-run delays a task briefly instead of stopping it for
a day. Both mechanisms use the cache, so **`CACHE_STORE` must be shared by all servers** (`database`, the default, or
`redis`; never `file` or `array` with more than one server). Production needs the standard Laravel scheduler cron
entry on one or more servers:

```cron
* * * * * cd /path-to-myDoc && php8.5 artisan schedule:run >> /dev/null 2>&1
```

Check the schedule with `php8.5 artisan schedule:list`. Locally, `composer run dev` (or `php8.5 artisan schedule:work`)
runs it.

### Timeouts

| Setting | Value | Why |
|---|---|---|
| SMTP socket timeout (`MAIL_TIMEOUT`) | 20 s | A hung mail server fails the attempt instead of blocking the worker |
| Email job timeout | 45 s | Enough for the SMTP exchange and the ledger update |
| SMS request timeout (`SMS_TIMEOUT`, `SMS_CONNECT_TIMEOUT`) | 10 s + 5 s | A hung provider fails the attempt |
| Google API request timeout (`GOOGLE_CALENDAR_TIMEOUT`, `GOOGLE_CALENDAR_CONNECT_TIMEOUT`) | 10 s + 5 s | A slow Google API cannot hold a worker or the disconnect request |
| Google Calendar sync job timeout | 60 s | Up to three Google requests plus the per-appointment lock wait |
| Database queue `retry_after` (`DB_QUEUE_RETRY_AFTER`) | 90 s | Must stay above every job timeout |
| Stale `sending` claim | 10 min | After this, an interrupted delivery can be taken over |

If you change any of these, keep the order: request timeouts < job timeouts < `retry_after` < stale-claim window.

### Production checklist

- **`APP_KEY`:** generate once and keep it stable. It encrypts sessions and the stored Google Calendar tokens;
  changing it signs everyone out and breaks every calendar connection. To rotate, put the old key in
  `APP_PREVIOUS_KEYS`. Set `APP_ENV=production`, `APP_DEBUG=false` and the real `APP_URL` (email links use it).
- **HTTPS:** serve the site over HTTPS and set `SESSION_SECURE_COOKIE=true`.
- **Database:** MySQL 8 with `DB_TIMEZONE=+06:00` (unchanged). Run `php8.5 artisan migrate --pretend`, then
  `migrate`, before or with the code. Never `migrate:fresh`, `migrate:refresh` or `db:wipe` on real data.
- **Queue:** `QUEUE_CONNECTION=database` (never `sync`), a supervised `queue:work` worker, and `queue:restart` after
  each deployment.
- **Scheduler:** the `schedule:run` cron entry above, and a shared `CACHE_STORE`.
- **Mail:** a real SMTP server (`MAIL_MAILER=smtp` and the `MAIL_*` settings), never `log`.
- **Google Calendar:** an OAuth client and published consent screen (see *Google Calendar*), with the production
  callback URL registered.
- **SMS (optional):** `SMS_DRIVER=bulksmsbd`, `SMS_SENDER_ID`, `BULKSMSBD_API_KEY`, and the server IP whitelisted
  if the provider enforces it. Leave `SMS_DRIVER=none` to disable SMS.
- **After deploying:** `php8.5 artisan config:cache`, `route:cache`, `view:cache`, `npm run build`, then
  `queue:restart`. Never commit `.env` or real credentials.

### Retries and failures

- A delivery failure (for example an SMTP error) is retried with increasing back-off (1, 5, 15 and 30 minutes).
  After **5 delivery failures** the job is failed and the row is marked `exhausted`.
- Every email is recorded once in `appointment_notifications` with its status (`pending`, `sending`, `sent`,
  `failed`, `exhausted`, `skipped`). A row marked `sent` is never sent again, so retries cannot produce duplicate
  emails.
- If a worker crashes or times out mid-send, the queue redelivers the job; it waits until the interrupted
  attempt's claim is 10 minutes old and then completes the delivery. This waiting does not use up the five
  delivery attempts (an overall ceiling of 15 runs still bounds a job that keeps crashing).
- Emails to deactivated accounts, invalid addresses, or about appointments that changed while queued are
  `skipped`.
- **Admin → Notification delivery issues** (`/admin/sms-issues`, active admins only) lists failed and exhausted
  emails and SMS problems with metadata only (reference, event, status, provider code, generic reason): never
  recipients, addresses, phone numbers or message text. Filter by channel and status. After fixing the cause (for
  example SMTP settings or SMS balance), an admin can **Retry** an `exhausted` message; the retry is conditional,
  so it is queued once even if clicked twice, and delivery still skips it if the appointment has changed since.
  `unconfirmed` and `undelivered` SMS can never be retried. Each retry is logged with the admin's user id.
- Exhausted jobs also appear in `php8.5 artisan queue:failed`. Retrying them with `queue:retry` sends nothing,
  because an `exhausted` row can only be returned to `pending` by the admin retry.

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
| Pending request expires | patient |
| Reminder 24 h and 2 h before (confirmed appointments only) | patient |

* **Opt-in:** every user starts opted out (`users.sms_notifications_enabled = false`). Patients enable SMS on
  *My profile*, doctors on *Account settings*; enabling requires a Bangladeshi mobile number. Email is unaffected.
* **Phone numbers:** forms accept `01XXXXXXXXX`, `+8801XXXXXXXXX`, `8801XXXXXXXXX`, `008801XXXXXXXXX` (spaces/dashes
  ignored) and store `8801XXXXXXXXX`. Older free-text numbers are kept as they are; an SMS is skipped when the
  current number is not a valid mobile number.
* **Content:** reference, doctor name, date and time only. No notes, reasons, symptoms, documents or links.
* **Reminders:** `mydoc:queue-appointment-reminders` runs every five minutes from the scheduler. A reminder is
  queued once per slot and start time, only for **confirmed** appointments (pending requests are never reminded)
  booked before the reminder was due. A request confirmed within the 60-minute catch-up window is reminded on the
  next run. Status, slot and start time are re-checked just before sending, so pending, cancelled, rejected,
  expired, completed, rescheduled or already-started appointments never get a reminder.
* **No duplicates:** BulkSMSBD has no idempotency key, so an SMS whose provider request may have gone out (timeout,
  unreadable response, provider internal error 1005, any undocumented code, crashed worker) is marked `unconfirmed`
  and never re-sent. Only failures that confirm the provider did not accept the SMS (connection refused, HTTP 429 or
  503, balance and IP-whitelist codes 1006, 1007, 1032) are retried; configuration errors (wrong sender id, disabled
  account, codes 1002, 1003, 1011–1031) are `undelivered`. A retryable SMS that still fails after five attempts
  becomes `exhausted`. Admins review all of them under *Notification delivery issues* and can retry only `exhausted` ones.

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

## Google Calendar

Each doctor can connect **their own** primary Google Calendar from *Account settings* (doctors only; assistants and
other roles get 403, and the doctor is always taken from the signed-in account, never from the request). The OAuth
`state` is checked on the callback, and tokens are stored encrypted.

* **What is synced:** confirmed appointments only. Confirming creates the event, rescheduling moves it, and
  cancelling, rejecting or expiring removes it; completed appointments keep their event as history. Events are titled
  `myDoc Appointment` with the consultation type only: never the patient's name, contact details, notes or
  documents. Event ids are derived from the appointment, so retries and reconnections update the same event.
* **Authorization:** access tokens are refreshed only when expired. If Google refuses the refresh token (revoked in
  the Google account, expired) or answers 401, the connection is kept but marked **Reconnection needed** on the
  settings page, and syncing stops instead of retrying. Reconnecting resumes it: confirmed appointments are synced
  again and events of appointments cancelled meanwhile are removed.
* **Disconnect** revokes the token and removes the connection. Events already in Google are **not** deleted; event
  records keep their Google id, so if the doctor reconnects to the same Google account, events of appointments that
  were cancelled meanwhile are removed then.
* **Recovery:** `mydoc:reconcile-google-calendar` runs hourly (without overlapping). For connected calendars and
  appointments that have not started, it re-syncs confirmed appointments whose event is missing or failed and
  removes events left for cancelled, rejected or expired ones. Records changed in the last 30 minutes are left to
  their own jobs.
* **Timeouts:** each Google request times out after `GOOGLE_CALENDAR_TIMEOUT` seconds (default 10, connect 5); the
  sync job times out after 60 seconds, below the queue's `retry_after` (90).

**Google Cloud setup:** create an OAuth client of type *Web application*, enable the Google Calendar API, configure
the OAuth consent screen (scopes `calendar.events` and `userinfo.email`), and add
`${APP_URL}/doctor/google-calendar/callback` as an authorized redirect URI. Set `GOOGLE_CALENDAR_CLIENT_ID`,
`GOOGLE_CALENDAR_CLIENT_SECRET` and `GOOGLE_CALENDAR_REDIRECT_URI` in `.env` (never commit real values), then run
`php8.5 artisan config:clear`. While the consent screen is in *Testing* mode, Google expires refresh tokens after
7 days, so doctors would have to reconnect weekly; publish the app for production.

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
