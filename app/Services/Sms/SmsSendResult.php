<?php

namespace App\Services\Sms;

/**
 * The provider accepted the message. The reference is the provider's message id or status code when available.
 */
final readonly class SmsSendResult
{
    public function __construct(public ?string $providerReference = null) {}
}
