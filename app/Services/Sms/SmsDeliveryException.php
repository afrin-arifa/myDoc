<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * Why an SMS was not (or may not have been) delivered. The message is a short, safe reason that never contains
 * a phone number, message text or credentials, so it may be stored in the ledger and logs.
 *
 *  - retryable: the provider certainly did not accept the message and a later attempt may succeed.
 *  - permanent: the provider rejected it and retrying will not help (e.g. wrong sender id).
 *  - uncertain: the request may have reached the provider (e.g. a read timeout), so it must not be retried,
 *    because the provider has no idempotency key and a retry could deliver a duplicate.
 */
class SmsDeliveryException extends RuntimeException
{
    public const RETRYABLE = 'retryable';

    public const PERMANENT = 'permanent';

    public const UNCERTAIN = 'uncertain';

    public function __construct(string $reason, public readonly string $outcome, public readonly ?string $providerCode = null)
    {
        parent::__construct($reason);
    }

    public static function retryable(string $reason, ?string $providerCode = null): self
    {
        return new self($reason, self::RETRYABLE, $providerCode);
    }

    public static function permanent(string $reason, ?string $providerCode = null): self
    {
        return new self($reason, self::PERMANENT, $providerCode);
    }

    public static function uncertain(string $reason, ?string $providerCode = null): self
    {
        return new self($reason, self::UNCERTAIN, $providerCode);
    }
}
