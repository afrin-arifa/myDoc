<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentNotification;
use App\Models\AppointmentNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * List of appointment emails and SMS that were not delivered as expected. It shows only ledger metadata: never the
 * recipient, their email address or phone number, the message text or provider credentials.
 *
 * The only action is retrying a message whose automatic retries ran out and that was certainly not delivered
 * ("exhausted"). Unconfirmed SMS (possibly delivered) and undelivered SMS (rejected by the provider) are never
 * retried from here, so a retry cannot produce a duplicate SMS.
 */
class AdminSmsIssueController extends Controller
{
    /**
     * @var list<string>
     */
    public const ISSUE_STATUSES = [
        AppointmentNotification::STATUS_UNCONFIRMED,
        AppointmentNotification::STATUS_FAILED,
        AppointmentNotification::STATUS_UNDELIVERED,
        AppointmentNotification::STATUS_EXHAUSTED,
    ];

    /**
     * @var list<string>
     */
    public const CHANNELS = [
        AppointmentNotification::CHANNEL_EMAIL,
        AppointmentNotification::CHANNEL_SMS,
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AppointmentNotification::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::ISSUE_STATUSES)],
            'channel' => ['nullable', Rule::in(self::CHANNELS)],
        ]);
        $status = $filters['status'] ?? null;
        $channel = $filters['channel'] ?? null;

        $issues = AppointmentNotification::query()
            ->whereIn('channel', $channel ? [$channel] : self::CHANNELS)
            ->whereIn('status', $status ? [$status] : self::ISSUE_STATUSES)
            ->select(['id', 'appointment_id', 'waitlist_offer_id', 'channel', 'type', 'status', 'attempts', 'provider_reference', 'last_error', 'provider_requested_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $counts = AppointmentNotification::query()
            ->whereIn('channel', $channel ? [$channel] : self::CHANNELS)
            ->whereIn('status', self::ISSUE_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.sms-issues.index', [
            'issues' => $issues,
            'status' => $status,
            'channel' => $channel,
            'counts' => collect(self::ISSUE_STATUSES)->mapWithKeys(fn (string $issueStatus): array => [$issueStatus => (int) ($counts[$issueStatus] ?? 0)]),
        ]);
    }

    /**
     * Queue an exhausted message once more. The status change is conditional, so two simultaneous retries queue it
     * only once; delivery then re-checks the recipient and whether the appointment still matches the message, so an
     * outdated message is skipped rather than sent.
     */
    public function retry(Request $request, AppointmentNotification $notification): RedirectResponse
    {
        Gate::authorize('retry', $notification);

        $queued = AppointmentNotification::query()
            ->whereKey($notification->id)
            ->where('status', AppointmentNotification::STATUS_EXHAUSTED)
            ->where(fn ($query) => $query
                ->where('channel', AppointmentNotification::CHANNEL_EMAIL)
                ->orWhereNull('provider_requested_at'))
            ->update([
                'status' => AppointmentNotification::STATUS_PENDING,
                'last_error' => 'Retry requested by an administrator.',
                'updated_at' => now(),
            ]);

        if ($queued !== 1) {
            return back()->with('warning', 'This notification can no longer be retried.');
        }

        SendAppointmentNotification::dispatch($notification->id);

        Log::info('Administrator retried an appointment notification.', [
            'appointment_notification_id' => $notification->id,
            'appointment_id' => $notification->appointment_id,
            'channel' => $notification->channel,
            'type' => $notification->type,
            'admin_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Notification #'.$notification->id.' was queued for another delivery attempt.');
    }
}
