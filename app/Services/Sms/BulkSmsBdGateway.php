<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * BulkSMSBD (https://bulksmsbd.com/bulksms-api-bangladesh.php).
 *
 * Documented: POST https://bulksmsbd.net/api/smsapi with api_key, type=text, number (8801XXXXXXXXX), senderid and
 * message; code 202 means "SMS Submitted Successfully"; error codes 1002–1032. Not documented: the JSON field
 * names of the response, a client reference / idempotency key, delivery reports and rate limits. The response is
 * therefore read defensively: only failures that confirm the message was not accepted (connection refused, HTTP
 * 429/503, balance and IP-whitelist codes) are retried. Code 1005, undocumented codes, timeouts and unreadable
 * responses are "uncertain" and never retried, because without an idempotency key a retry could deliver a
 * duplicate.
 */
class BulkSmsBdGateway implements SmsGateway
{
    public const SUCCESS_CODE = 202;

    /**
     * Provider codes that confirm the message was not accepted for a temporary reason (balance or IP whitelist),
     * so a later retry is safe and may succeed.
     *
     * @var array<int, string>
     */
    public const RETRYABLE_CODES = [
        1006 => 'SMS balance validity has expired.',
        1007 => 'SMS balance is insufficient.',
        1032 => 'Server IP address is not whitelisted with the provider.',
    ];

    /**
     * Provider codes whose outcome is unknown: the provider may still have queued the message. They are never
     * retried automatically. Any code not listed in RETRYABLE_CODES or PERMANENT_CODES is treated the same way.
     *
     * @var array<int, string>
     */
    public const UNCERTAIN_CODES = [
        1005 => 'Provider reported an internal error; delivery is unknown.',
    ];

    /**
     * Provider codes that need an account or configuration change; retrying the same request will not help.
     *
     * @var array<int, string>
     */
    public const PERMANENT_CODES = [
        1002 => 'Sender ID is incorrect or disabled.',
        1003 => 'Provider rejected the request as incomplete.',
        1011 => 'Provider account was not found.',
        1012 => 'Masking SMS must be sent in Bengali.',
        1013 => 'Sender ID has no gateway for this API key.',
        1014 => 'Sender type was not found for this API key.',
        1015 => 'Sender ID has no valid gateway for this API key.',
        1016 => 'Sender type has no active price.',
        1017 => 'Sender type has no price information.',
        1018 => 'Provider account is disabled.',
        1019 => 'Sender type price is disabled for this account.',
        1020 => 'Provider parent account was not found.',
        1021 => 'Provider parent account has no active price.',
        1031 => 'Provider account is not verified.',
    ];

    /**
     * cURL errors raised before the request could reach the provider (DNS, connection refused, connect timeout,
     * TLS handshake). Read timeouts and dropped responses are not listed: the provider may have accepted those.
     */
    private const NOT_SENT_CURL_ERRORS = [6, 7, 35];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $apiKey,
        private readonly ?string $senderId,
        private readonly string $baseUrl = 'https://bulksmsbd.net/api',
        private readonly int $timeout = 10,
        private readonly int $connectTimeout = 5,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->senderId);
    }

    /**
     * BulkSMSBD has no documented idempotency parameter, so $reference is not sent; duplicate protection relies on
     * the appointment notification ledger and its atomic claim.
     */
    public function send(string $to, string $message, string $reference): SmsSendResult
    {
        if (! $this->isConfigured()) {
            throw SmsDeliveryException::permanent('No SMS provider is configured.');
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post(rtrim($this->baseUrl, '/').'/smsapi', [
                    'api_key' => $this->apiKey,
                    'type' => 'text',
                    'number' => $to,
                    'senderid' => $this->senderId,
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception);
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): SmsSendResult
    {
        if ($response->status() === 429) {
            throw SmsDeliveryException::retryable('Provider rate limit reached.', 'http-429');
        }

        if ($response->status() === 503) {
            throw SmsDeliveryException::retryable('Provider is temporarily unavailable.', 'http-503');
        }

        if ($response->failed()) {
            throw SmsDeliveryException::uncertain('Provider returned HTTP '.$response->status().'.', 'http-'.$response->status());
        }

        $code = $this->responseCode($response);

        if ($code === self::SUCCESS_CODE) {
            $messageId = $response->json('message_id');

            return new SmsSendResult(is_scalar($messageId) && $messageId !== '' ? mb_substr((string) $messageId, 0, 64) : (string) self::SUCCESS_CODE);
        }

        if ($code === null) {
            throw SmsDeliveryException::uncertain('Provider response could not be understood.');
        }

        if (isset(self::PERMANENT_CODES[$code])) {
            throw SmsDeliveryException::permanent(self::PERMANENT_CODES[$code], (string) $code);
        }

        if (isset(self::RETRYABLE_CODES[$code])) {
            throw SmsDeliveryException::retryable(self::RETRYABLE_CODES[$code], (string) $code);
        }

        throw SmsDeliveryException::uncertain(self::UNCERTAIN_CODES[$code] ?? 'Provider returned an undocumented response code; delivery is unknown.', (string) $code);
    }

    /**
     * The documentation lists codes but not the response field, so accept the common shapes: {"response_code": 202},
     * {"code": 202}, or a bare code.
     */
    private function responseCode(Response $response): ?int
    {
        $json = $response->json();
        $candidate = is_array($json) ? ($json['response_code'] ?? $json['code'] ?? null) : trim($response->body());

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    private function connectionFailure(ConnectionException $exception): SmsDeliveryException
    {
        $message = $exception->getMessage();
        $notSent = (preg_match('/cURL error (\d+)/', $message, $matches) === 1 && in_array((int) $matches[1], self::NOT_SENT_CURL_ERRORS, true))
            || str_contains($message, 'Connection timed out')
            || str_contains($message, 'Resolving timed out');

        return $notSent
            ? SmsDeliveryException::retryable('Could not connect to the SMS provider.', 'connection')
            : SmsDeliveryException::uncertain('SMS provider did not respond in time.', 'timeout');
    }
}
