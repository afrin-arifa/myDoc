<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS driver
    |--------------------------------------------------------------------------
    |
    | "none" (default): no provider; SMS ledger rows are skipped and nothing is sent.
    | "log": development only; records a redacted line instead of sending.
    | "bulksmsbd": BulkSMSBD HTTP API (requires an API key and an approved sender id).
    |
    | Automated tests never use a real provider: the testing environment forces "none" unless a test swaps in
    | App\Services\Sms\FakeSmsGateway.
    |
    */

    'default' => env('SMS_DRIVER', 'none'),

    /*
     * The approved sender id from the provider panel: the non-masking number, or a registered masking name such
     * as "myDoc". BulkSMSBD requires masking SMS to be written in Bengali (error 1012); messages here are English.
     */
    'sender_id' => env('SMS_SENDER_ID'),

    'timeout' => (int) env('SMS_TIMEOUT', 10),

    'connect_timeout' => (int) env('SMS_CONNECT_TIMEOUT', 5),

    'drivers' => [
        'bulksmsbd' => [
            'base_url' => env('BULKSMSBD_BASE_URL', 'https://bulksmsbd.net/api'),
            'api_key' => env('BULKSMSBD_API_KEY'),
        ],
    ],

    /*
     * Appointment reminders, in hours before the start time. A reminder is queued once the appointment is within
     * that many hours, for up to window_minutes afterwards (so a short scheduler outage does not lose it), and only
     * for appointments booked before the reminder became due.
     */
    'reminders' => [
        'hours' => array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) env('SMS_REMINDER_HOURS', '24,2'))),
            fn (int $hours): bool => $hours > 0,
        ))),
        'window_minutes' => (int) env('SMS_REMINDER_WINDOW_MINUTES', 60),
    ],

];
