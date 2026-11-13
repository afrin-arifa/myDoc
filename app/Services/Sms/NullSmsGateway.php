<?php

namespace App\Services\Sms;

/**
 * Used when no SMS provider is configured (the default). Nothing is sent and SMS ledger rows are skipped.
 */
class NullSmsGateway implements SmsGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $message, string $reference): SmsSendResult
    {
        throw SmsDeliveryException::permanent('No SMS provider is configured.');
    }
}
