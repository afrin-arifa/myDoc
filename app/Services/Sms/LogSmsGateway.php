<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: records that an SMS would have been sent, without the number or the text.
 */
class LogSmsGateway implements SmsGateway
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message, string $reference): SmsSendResult
    {
        Log::info('SMS not sent (log driver).', [
            'reference' => $reference,
            'recipient' => '…'.substr($to, -3),
            'length' => mb_strlen($message),
        ]);

        return new SmsSendResult('log');
    }
}
