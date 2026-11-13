<?php

namespace App\Services\Sms;

/**
 * A provider that sends one SMS. Implementations must never log the phone number or the message body.
 *
 * Errors are reported as SmsDeliveryException, classified so the caller can decide safely whether to retry:
 * retrying is only allowed when the provider certainly did not accept the message.
 */
interface SmsGateway
{
    /**
     * False when the driver lacks credentials, so the caller can skip instead of failing.
     */
    public function isConfigured(): bool;

    /**
     * @param  string  $to  Normalized Bangladeshi mobile number (8801XXXXXXXXX).
     * @param  string  $reference  Stable idempotency key for this message. Passed to providers that support one.
     *
     * @throws SmsDeliveryException
     */
    public function send(string $to, string $message, string $reference): SmsSendResult;
}
