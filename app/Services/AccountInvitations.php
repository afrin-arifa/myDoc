<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;

/**
 * Invitation tokens for admin-provisioned accounts, built on one of Laravel's password brokers: tokens are random,
 * stored only as a hash, expire, are throttled, and are single use (consumed on acceptance; a new invitation
 * replaces the previous one). Status values are shared by every account type that is activated by invitation.
 */
class AccountInvitations
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITATION_PENDING = 'invitation_pending';

    public const STATUS_INVITATION_EXPIRED = 'invitation_expired';

    public const STATUS_DEACTIVATED = 'deactivated';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INVITATION_PENDING => 'Invitation pending',
        self::STATUS_INVITATION_EXPIRED => 'Invitation expired',
        self::STATUS_DEACTIVATED => 'Deactivated',
    ];

    /**
     * Minimum duration of a token check, so responses do not reveal whether an email has an invitation.
     */
    private const TOKEN_CHECK_MICROSECONDS = 200_000;

    public function __construct(private readonly string $brokerName, private readonly string $table) {}

    /**
     * Create a token for the user, replacing any earlier one, and return the raw token for the invitation link.
     */
    public function issue(User $user): string
    {
        return $this->broker()->createToken($user);
    }

    public function recentlyIssued(User $user): bool
    {
        return $this->broker()->getRepository()->recentlyCreatedToken($user);
    }

    /**
     * Whether the raw token is the user's current, unexpired token.
     */
    public function matches(User $user, #[\SensitiveParameter] string $token): bool
    {
        return $this->broker()->tokenExists($user, $token);
    }

    /**
     * Run a token lookup in constant time.
     *
     * @template T
     *
     * @param  callable(): T  $lookup
     * @return T
     */
    public function timeboxed(callable $lookup): mixed
    {
        return (new Timebox)->call(fn () => $lookup(), self::TOKEN_CHECK_MICROSECONDS);
    }

    /**
     * Invalidate the user's outstanding invitation (used once, revoked, or replaced).
     */
    public function revoke(User $user): void
    {
        $this->broker()->deleteToken($user);
    }

    /**
     * Invalidate any invitation sent to an address. Returns whether one existed.
     */
    public function revokeEmail(string $email): bool
    {
        return DB::table($this->table)->where('email', $email)->delete() > 0;
    }

    public function issuedAt(string $email): ?CarbonImmutable
    {
        $createdAt = DB::table($this->table)->where('email', $email)->value('created_at');

        return $createdAt ? CarbonImmutable::parse($createdAt) : null;
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, CarbonImmutable>
     */
    public function issuedAtFor(array $emails): array
    {
        return DB::table($this->table)
            ->whereIn('email', $emails)
            ->pluck('created_at', 'email')
            ->map(fn ($createdAt) => CarbonImmutable::parse($createdAt))
            ->all();
    }

    public function expiresAt(?CarbonImmutable $issuedAt): ?CarbonImmutable
    {
        return $issuedAt?->addMinutes($this->expiryMinutes());
    }

    /**
     * Derived account status: active accounts are active; inactive accounts are pending or expired while they hold
     * an invitation, otherwise deactivated.
     */
    public function status(User $user, ?CarbonImmutable $issuedAt): string
    {
        return match (true) {
            $user->deactivated_at === null => self::STATUS_ACTIVE,
            $issuedAt === null => self::STATUS_DEACTIVATED,
            $this->expiresAt($issuedAt)->isPast() => self::STATUS_INVITATION_EXPIRED,
            default => self::STATUS_INVITATION_PENDING,
        };
    }

    public function expiryMinutes(): int
    {
        return (int) config("auth.passwords.{$this->brokerName}.expire", 4320);
    }

    private function broker(): PasswordBroker
    {
        return Password::broker($this->brokerName);
    }
}
