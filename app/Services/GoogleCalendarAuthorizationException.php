<?php

namespace App\Services;

use RuntimeException;

/**
 * The doctor's Google authorization no longer works: the refresh token was revoked or expired (OAuth
 * "invalid_grant"), no refresh token is stored, the calendar permission was not granted, or Google
 * answered 401. Retrying cannot help until the doctor
 * reconnects, so sync jobs mark the connection as needing reconnection instead of retrying.
 */
class GoogleCalendarAuthorizationException extends RuntimeException {}
