<?php

namespace App\Services\Sms;

use Closure;

/**
 * Test driver: keeps sent messages in memory. A responder closure can throw SmsDeliveryException to simulate
 * provider failures. Never contacts a provider.
 */
class FakeSmsGateway implements SmsGateway
{
    /**
     * @var list<array{to: string, message: string, reference: string}>
     */
    public array $sent = [];

    public int $attempts = 0;

    public function __construct(private bool $configured = true, private ?Closure $responder = null) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function failUsing(?Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function send(string $to, string $message, string $reference): SmsSendResult
    {
        $this->attempts++;

        if ($this->responder !== null) {
            ($this->responder)($this->attempts, $to, $message);
        }

        $this->sent[] = ['to' => $to, 'message' => $message, 'reference' => $reference];

        return new SmsSendResult('fake-'.count($this->sent));
    }

    /**
     * @return list<array{to: string, message: string, reference: string}>
     */
    public function sentTo(string $to): array
    {
        return array_values(array_filter($this->sent, fn (array $sms): bool => $sms['to'] === $to));
    }
}
