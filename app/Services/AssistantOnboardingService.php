<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Account\AssistantInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisioning of Doctor Assistant accounts by administrators.
 *
 * An assistant account gets the assistant role from this service, never from request input. Normally it is created
 * inactive with an unknown random password, and the assistant activates it by setting their own password through a
 * single-use, expiring invitation (separate from doctor invitations). Alternatively the administrator sets the first
 * password, which activates the account at once without an invitation. Deactivation revokes any outstanding invitation and removes every
 * doctor assignment (recorded in the assignment history), so reactivation never restores earlier access.
 */
class AssistantOnboardingService
{
    public const BROKER = 'assistant_invitations';

    public const TOKEN_TABLE = 'assistant_invitation_tokens';

    public const INVITATION_SENT = 'sent';

    public const INVITATION_THROTTLED = 'throttled';

    public const INVITATION_NOT_NEEDED = 'not_needed';

    public const INVITATION_FAILED = 'failed';

    public const INVITATION_NOT_ALLOWED = 'not_allowed';

    public function __construct(private readonly AssistantAssignmentService $assignments) {}

    public function invitations(): AccountInvitations
    {
        return new AccountInvitations(self::BROKER, self::TOKEN_TABLE);
    }

    /**
     * Create an assistant account. Without a password it is inactive and its invitation is sent once the transaction
     * commits; with a password it is active immediately, no invitation exists and it can be assigned to doctors in
     * the same step.
     *
     * @param  array{name: string, email: string, phone?: string|null, password?: string|null, doctor_profile_ids?: list<int>}  $account
     * @return array{assistant: User, invitation: string}
     */
    public function createAssistant(User $admin, #[\SensitiveParameter] array $account): array
    {
        $this->ensureAdmin($admin);
        $password = $account['password'] ?? null;
        $invitation = $password === null ? self::INVITATION_FAILED : self::INVITATION_NOT_NEEDED;

        $assistant = DB::transaction(function () use ($admin, $account, $password, &$invitation): User {
            $assistant = new User;
            $assistant->fill([
                'name' => $account['name'],
                'email' => $account['email'],
                'phone' => $account['phone'] ?? null,
            ]);
            // Role, status and password are set explicitly. Without a chosen password it is a random unknown value
            // (hashed by the model cast) that is replaced when the assistant accepts the invitation.
            // email_verified_at marks an account whose password has been set.
            $assistant->forceFill([
                'role' => User::ROLE_ASSISTANT,
                'password' => $password ?? Str::password(64),
                'deactivated_at' => $password === null ? now() : null,
                'email_verified_at' => $password === null ? null : now(),
            ])->save();

            if ($password !== null) {
                if (($account['doctor_profile_ids'] ?? []) !== []) {
                    $this->assignments->assign($admin, $assistant, $account['doctor_profile_ids']);
                }

                return $assistant;
            }

            $token = $this->invitations()->issue($assistant);
            DB::afterCommit(function () use ($assistant, $token, &$invitation): void {
                $invitation = $this->deliver($assistant, $token, false);
            });

            return $assistant;
        });

        return ['assistant' => $assistant, 'invitation' => $invitation];
    }

    /**
     * Send a fresh invitation (invalidating any earlier link) to an assistant who has not set a password yet.
     */
    public function resendInvitation(User $admin, User $assistant): string
    {
        $this->ensureAdmin($admin);
        $result = self::INVITATION_NOT_ALLOWED;

        DB::transaction(function () use ($assistant, &$result): void {
            $locked = $this->lock($assistant);

            if (! $this->awaitsInvitation($locked)) {
                return;
            }

            if ($this->invitations()->recentlyIssued($locked)) {
                $result = self::INVITATION_THROTTLED;

                return;
            }

            $token = $this->invitations()->issue($locked);
            DB::afterCommit(function () use ($locked, $token, &$result): void {
                $result = $this->deliver($locked, $token, true);
            });
        });

        return $result;
    }

    /**
     * The assistant for a valid, unused, unexpired assistant invitation; null otherwise (including doctor or patient
     * accounts and doctor invitation tokens).
     */
    public function invitedAssistant(?string $email, #[\SensitiveParameter] ?string $token): ?User
    {
        return $this->invitations()->timeboxed(function () use ($email, $token): ?User {
            if (blank($email) || blank($token)) {
                return null;
            }

            $assistant = $this->pendingQuery()->where('email', $email)->first();

            return $assistant && $this->invitations()->matches($assistant, $token) ? $assistant : null;
        });
    }

    /**
     * Set the assistant's chosen password and activate the account. The token is consumed in the same transaction,
     * so it can never be used twice. Returns null for any invalid, expired, revoked or used invitation.
     */
    public function acceptInvitation(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): ?User
    {
        if (! $this->invitedAssistant($email, $token)) {
            return null;
        }

        return DB::transaction(function () use ($email, $token, $password): ?User {
            $locked = $this->pendingQuery()->where('email', $email)->lockForUpdate()->first();

            if (! $locked || ! $this->invitations()->matches($locked, $token)) {
                return null;
            }

            $locked->forceFill([
                'password' => $password,
                'deactivated_at' => null,
                'email_verified_at' => $locked->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();
            $this->invitations()->revoke($locked);

            return $locked;
        });
    }

    /**
     * An administrator sets the assistant's password. An account still waiting for its invitation becomes active and
     * the invitation stops working; an active assistant is signed out everywhere. A deactivated account that had
     * access before is refused, so a new password can never bring back removed access.
     *
     * @return bool Whether the password was set.
     */
    public function setPassword(User $admin, User $assistant, #[\SensitiveParameter] string $password): bool
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($admin, $assistant, $password): bool {
            $locked = $this->lock($assistant);

            if (! $this->canSetPassword($locked)) {
                return false;
            }

            $locked->forceFill([
                'password' => $password,
                'deactivated_at' => null,
                'email_verified_at' => $locked->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();
            $this->invitations()->revoke($locked);
            Log::info('An administrator set a Doctor Assistant password.', ['assistant_id' => $locked->id, 'admin_id' => $admin->id]);

            return true;
        });
    }

    /**
     * Active assistants, and accounts that never had a password of their own.
     */
    public function canSetPassword(User $assistant): bool
    {
        return $assistant->deactivated_at === null || $this->awaitsInvitation($assistant);
    }

    /**
     * Reactivate an assistant who has set a password before. Assignments removed at deactivation are not restored.
     * Accounts that never accepted their invitation must accept a new one instead.
     */
    public function activate(User $admin, User $assistant): bool
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($assistant): bool {
            $locked = $this->lock($assistant);

            if ($this->statusFor($locked) !== AccountInvitations::STATUS_DEACTIVATED || $locked->email_verified_at === null) {
                return false;
            }

            $locked->forceFill(['deactivated_at' => null])->save();

            return true;
        });
    }

    /**
     * Deactivate the assistant: revoke any outstanding invitation and remove every doctor assignment, each recorded
     * in the assignment history with the admin as actor. Access ends on the assistant's next request.
     *
     * @return int Number of assignments removed.
     */
    public function deactivate(User $admin, User $assistant): int
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($admin, $assistant): int {
            $locked = $this->lock($assistant);
            $locked->forceFill(['deactivated_at' => $locked->deactivated_at ?? now()])->save();
            $this->invitations()->revoke($locked);

            return $this->assignments->removeAll($admin, $locked);
        });
    }

    /**
     * Update the permitted details (name, email, phone). If the email changes while an invitation is outstanding,
     * the old link stops working and a fresh invitation goes to the new address. Returns the invitation result, or
     * null when no invitation was involved.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $details
     */
    public function updateDetails(User $admin, User $assistant, array $details): ?string
    {
        $this->ensureAdmin($admin);
        $invitation = null;

        DB::transaction(function () use ($assistant, $details, &$invitation): void {
            $locked = $this->lock($assistant);
            $oldEmail = $locked->email;
            $locked->fill([
                'name' => $details['name'],
                'email' => $details['email'],
                'phone' => $details['phone'] ?? null,
            ])->save();

            if ($oldEmail === $locked->email || ! $this->invitations()->revokeEmail($oldEmail) || $locked->deactivated_at === null) {
                return;
            }

            $token = $this->invitations()->issue($locked);
            DB::afterCommit(function () use ($locked, $token, &$invitation): void {
                $invitation = $this->deliver($locked, $token, false);
            });
        });

        return $invitation;
    }

    public function statusFor(User $assistant): string
    {
        return $this->invitations()->status($assistant, $this->invitations()->issuedAt($assistant->email));
    }

    /**
     * Statuses for many assistants with one invitation query.
     *
     * @param  Collection<int, User>  $assistants
     * @return array<int, string> Keyed by user id.
     */
    public function statusesFor(Collection $assistants): array
    {
        $issued = $this->invitations()->issuedAtFor($assistants->pluck('email')->all());

        return $assistants->mapWithKeys(fn (User $assistant): array => [
            $assistant->id => $this->invitations()->status($assistant, $issued[$assistant->email] ?? null),
        ])->all();
    }

    public function invitationExpiresAt(User $assistant): ?CarbonImmutable
    {
        return $this->invitations()->expiresAt($this->invitations()->issuedAt($assistant->email));
    }

    /**
     * Whether the assistant can be sent an invitation: they have never set a password and are not active.
     */
    public function awaitsInvitation(User $assistant): bool
    {
        return $assistant->deactivated_at !== null && $assistant->email_verified_at === null;
    }

    private function deliver(User $assistant, #[\SensitiveParameter] string $token, bool $isReminder): string
    {
        try {
            $assistant->notify(new AssistantInvitationNotification($token, $this->invitations()->expiryMinutes(), $isReminder));

            return self::INVITATION_SENT;
        } catch (Throwable $exception) {
            Log::warning('Assistant invitation email could not be sent.', [
                'user_id' => $assistant->id,
                'exception' => $exception::class,
            ]);

            return self::INVITATION_FAILED;
        }
    }

    private function lock(User $assistant): User
    {
        return User::query()->whereKey($assistant->id)->where('role', User::ROLE_ASSISTANT)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Builder<User>
     */
    private function pendingQuery()
    {
        return User::query()->where('role', User::ROLE_ASSISTANT)->whereNotNull('deactivated_at');
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureAdmin(User $admin): void
    {
        if ($admin->role !== 'admin' || $admin->deactivated_at !== null) {
            throw new AuthorizationException('Only administrators can manage assistant accounts.');
        }
    }
}
