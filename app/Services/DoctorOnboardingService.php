<?php

namespace App\Services;

use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\Account\DoctorAccountActivatedNotification;
use App\Notifications\Account\DoctorInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

/**
 * Admin-managed doctor onboarding.
 *
 * Account access is controlled solely by users.deactivated_at (the existing status mechanism). The onboarding
 * state is derived from it together with the "doctor_invitations" password broker's token store:
 *  - active:              deactivated_at is null
 *  - invitation_pending:  deactivated, with an unexpired invitation token
 *  - invitation_expired:  deactivated, with an expired invitation token
 *  - deactivated:         deactivated, with no invitation token
 *
 * Invitation tokens are generated, hashed, expired and throttled by Laravel's DatabaseTokenRepository; the raw
 * token only exists in memory and in the invitation email.
 */
class DoctorOnboardingService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITATION_PENDING = 'invitation_pending';

    public const STATUS_INVITATION_EXPIRED = 'invitation_expired';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const INVITATION_SENT = 'sent';

    public const INVITATION_THROTTLED = 'throttled';

    public const INVITATION_FAILED = 'failed';

    public const INVITATION_NOT_ALLOWED = 'not_allowed';

    private const INVITATION_TABLE = 'doctor_invitation_tokens';

    /**
     * Minimum duration of an invitation token check, so responses do not reveal whether an email belongs to an
     * invited doctor.
     */
    private const TOKEN_CHECK_MICROSECONDS = 200_000;

    /**
     * Create an inactive doctor account with its profile and send the invitation after the transaction commits.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $account
     * @param  array<string, mixed>  $profile
     * @param  list<int>  $specialtyIds
     * @return array{doctor: User, invitation: string}
     */
    public function createDoctor(array $account, array $profile, array $specialtyIds): array
    {
        $invitation = self::INVITATION_FAILED;

        $doctor = DB::transaction(function () use ($account, $profile, $specialtyIds, &$invitation): User {
            $doctor = new User;
            $doctor->fill([
                'name' => $account['name'],
                'email' => $account['email'],
                'phone' => $account['phone'] ?? null,
            ]);
            // Role, status and password are set explicitly, never from request input. The password is a random,
            // unknown value (hashed by the model cast) that is replaced when the doctor accepts the invitation.
            $doctor->forceFill([
                'role' => 'doctor',
                'password' => Str::password(64),
                'deactivated_at' => now(),
            ])->save();

            $doctorProfile = $doctor->doctorProfile()->create($profile);
            $doctorProfile->specialties()->sync($specialtyIds);

            $token = $this->broker()->createToken($doctor);
            DB::afterCommit(function () use ($doctor, $token, &$invitation): void {
                $invitation = $this->deliverInvitation($doctor, $token, false);
            });

            return $doctor;
        });

        return ['doctor' => $doctor, 'invitation' => $invitation];
    }

    /**
     * Issue a fresh invitation (invalidating any previous link) to a doctor who has not activated their account.
     */
    public function resendInvitation(User $doctor): string
    {
        $result = self::INVITATION_NOT_ALLOWED;

        DB::transaction(function () use ($doctor, &$result): void {
            $locked = User::query()->whereKey($doctor->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($this->statusFor($locked), [self::STATUS_INVITATION_PENDING, self::STATUS_INVITATION_EXPIRED], true)) {
                return;
            }

            if ($this->broker()->getRepository()->recentlyCreatedToken($locked)) {
                $result = self::INVITATION_THROTTLED;

                return;
            }

            $token = $this->broker()->createToken($locked);
            DB::afterCommit(function () use ($locked, $token, &$result): void {
                $result = $this->deliverInvitation($locked, $token, true);
            });
        });

        return $result;
    }

    /**
     * Return the invited doctor when the email and token form a valid, unused, unexpired invitation.
     */
    public function invitedDoctor(?string $email, #[\SensitiveParameter] ?string $token): ?User
    {
        return (new Timebox)->call(function () use ($email, $token): ?User {
            if (blank($email) || blank($token)) {
                return null;
            }

            $doctor = $this->pendingDoctorQuery()->where('email', $email)->first();

            return $doctor && $this->broker()->tokenExists($doctor, $token) ? $doctor : null;
        }, self::TOKEN_CHECK_MICROSECONDS);
    }

    /**
     * Activate the invited account with the doctor's chosen password. The token is consumed in the same
     * transaction, so it can never be used twice. Returns null for any invalid, expired or used invitation.
     */
    public function acceptInvitation(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): ?User
    {
        $doctor = $this->invitedDoctor($email, $token);

        if (! $doctor) {
            return null;
        }

        return DB::transaction(function () use ($doctor, $token, $password): ?User {
            $locked = $this->pendingDoctorQuery()->whereKey($doctor->id)->lockForUpdate()->first();

            if (! $locked || ! $this->broker()->tokenExists($locked, $token)) {
                return null;
            }

            $locked->forceFill([
                'password' => $password,
                'deactivated_at' => null,
                'email_verified_at' => $locked->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();
            $this->broker()->deleteToken($locked);

            $locked->notify(new DoctorAccountActivatedNotification);

            return $locked;
        });
    }

    /**
     * Reactivate a previously active doctor. Accounts still awaiting their invitation must be activated by the
     * doctor accepting it, so the admin never activates an account whose password nobody knows.
     */
    public function activate(User $doctor): bool
    {
        return DB::transaction(function () use ($doctor): bool {
            $locked = User::query()->whereKey($doctor->id)->where('role', 'doctor')->lockForUpdate()->first();

            if (! $locked || $this->statusFor($locked) !== self::STATUS_DEACTIVATED) {
                return false;
            }

            $locked->forceFill(['deactivated_at' => null])->save();

            return true;
        });
    }

    /**
     * Deactivate a doctor. Any outstanding invitation is revoked. Appointments, schedules and calendar records
     * are kept intact.
     */
    public function deactivate(User $doctor): bool
    {
        return DB::transaction(function () use ($doctor): bool {
            $locked = User::query()->whereKey($doctor->id)->where('role', 'doctor')->lockForUpdate()->first();

            if (! $locked) {
                return false;
            }

            $locked->forceFill(['deactivated_at' => $locked->deactivated_at ?? now()])->save();
            $this->broker()->deleteToken($locked);

            return true;
        });
    }

    /**
     * Call inside the transaction that changed a doctor's email. If the doctor still has an outstanding
     * invitation, the old token is destroyed (links sent to the old address stop working) and a fresh invitation
     * is issued to the new address once the transaction commits. $invitation receives the delivery result, or
     * stays null when no invitation was involved.
     */
    public function emailChanged(User $doctor, string $oldEmail, ?string &$invitation = null): void
    {
        if ($oldEmail === $doctor->email) {
            return;
        }

        $hadInvitation = DB::table(self::INVITATION_TABLE)->where('email', $oldEmail)->delete() > 0;

        if (! $hadInvitation || $doctor->role !== 'doctor' || $doctor->deactivated_at === null) {
            return;
        }

        $token = $this->broker()->createToken($doctor);
        DB::afterCommit(function () use ($doctor, $token, &$invitation): void {
            $invitation = $this->deliverInvitation($doctor, $token, false);
        });
    }

    /**
     * Apply an admin's requested access change to a doctor through the onboarding rules.
     *
     * @return bool False when the change is not allowed (activating an account still awaiting its invitation).
     */
    public function setAccess(User $doctor, bool $active): bool
    {
        if (! $active) {
            return $this->deactivate($doctor);
        }

        return $this->statusFor($doctor) === self::STATUS_ACTIVE || $this->activate($doctor);
    }

    public function statusFor(User $doctor): string
    {
        if ($doctor->deactivated_at === null) {
            return self::STATUS_ACTIVE;
        }

        $createdAt = DB::table(self::INVITATION_TABLE)->where('email', $doctor->email)->value('created_at');

        return $this->statusFromInvitation($createdAt);
    }

    /**
     * Statuses for many doctors with a single invitation query.
     *
     * @param  iterable<DoctorProfile>  $profiles
     * @return array<int, string> Keyed by doctor profile id.
     */
    public function statusesFor(iterable $profiles): array
    {
        $profiles = collect($profiles);
        $invitations = DB::table(self::INVITATION_TABLE)
            ->whereIn('email', $profiles->pluck('user.email')->filter()->all())
            ->pluck('created_at', 'email');

        return $profiles->mapWithKeys(fn (DoctorProfile $profile): array => [
            $profile->id => $profile->user->deactivated_at === null
                ? self::STATUS_ACTIVE
                : $this->statusFromInvitation($invitations[$profile->user->email] ?? null),
        ])->all();
    }

    public function invitationExpiresAt(User $doctor): ?CarbonImmutable
    {
        $createdAt = DB::table(self::INVITATION_TABLE)->where('email', $doctor->email)->value('created_at');

        return $createdAt ? CarbonImmutable::parse($createdAt)->addMinutes($this->expiryMinutes()) : null;
    }

    /**
     * Filter a doctor profile query by derived account status.
     *
     * @param  Builder<DoctorProfile>  $query
     */
    public function applyStatusFilter(Builder $query, string $status): void
    {
        $cutoff = now()->subMinutes($this->expiryMinutes());
        $invitation = fn ($tokens) => $tokens->from(self::INVITATION_TABLE)->whereColumn(self::INVITATION_TABLE.'.email', 'users.email');

        $query->whereHas('user', fn (Builder $users) => match ($status) {
            self::STATUS_ACTIVE => $users->whereNull('deactivated_at'),
            self::STATUS_INVITATION_PENDING => $users->whereNotNull('deactivated_at')
                ->whereExists(fn ($tokens) => $invitation($tokens)->where('created_at', '>', $cutoff)),
            self::STATUS_INVITATION_EXPIRED => $users->whereNotNull('deactivated_at')
                ->whereExists(fn ($tokens) => $invitation($tokens)->where('created_at', '<=', $cutoff)),
            default => $users->whereNotNull('deactivated_at')->whereNotExists($invitation),
        });
    }

    public function expiryMinutes(): int
    {
        return (int) config('auth.passwords.doctor_invitations.expire', 4320);
    }

    private function statusFromInvitation(mixed $createdAt): string
    {
        if ($createdAt === null) {
            return self::STATUS_DEACTIVATED;
        }

        return CarbonImmutable::parse($createdAt)->addMinutes($this->expiryMinutes())->isPast()
            ? self::STATUS_INVITATION_EXPIRED
            : self::STATUS_INVITATION_PENDING;
    }

    /**
     * Send the invitation immediately so the raw token is never written to the queue. A delivery failure is
     * logged without the token and reported to the admin; the account and invitation remain valid for a resend.
     */
    private function deliverInvitation(User $doctor, #[\SensitiveParameter] string $token, bool $isReminder): string
    {
        try {
            $doctor->notify(new DoctorInvitationNotification($token, $this->expiryMinutes(), $isReminder));

            return self::INVITATION_SENT;
        } catch (Throwable $exception) {
            Log::warning('Doctor invitation email could not be sent.', [
                'user_id' => $doctor->id,
                'exception' => $exception::class,
            ]);

            return self::INVITATION_FAILED;
        }
    }

    /**
     * @return Builder<User>
     */
    private function pendingDoctorQuery(): Builder
    {
        return User::query()->where('role', 'doctor')->whereNotNull('deactivated_at');
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('doctor_invitations');
    }
}
