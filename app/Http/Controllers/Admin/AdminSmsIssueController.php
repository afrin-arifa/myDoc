<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppointmentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only list of SMS that were not delivered as expected. It shows only ledger metadata: never the recipient,
 * their phone number, the message text or provider credentials, and it offers no resend action, because an
 * "unconfirmed" SMS may already have been delivered.
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
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AppointmentNotification::class);

        $status = $request->validate([
            'status' => ['nullable', Rule::in(self::ISSUE_STATUSES)],
        ])['status'] ?? null;

        $issues = AppointmentNotification::query()
            ->where('channel', AppointmentNotification::CHANNEL_SMS)
            ->whereIn('status', $status ? [$status] : self::ISSUE_STATUSES)
            ->select(['id', 'appointment_id', 'type', 'status', 'attempts', 'provider_reference', 'last_error', 'provider_requested_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $counts = AppointmentNotification::query()
            ->where('channel', AppointmentNotification::CHANNEL_SMS)
            ->whereIn('status', self::ISSUE_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.sms-issues.index', [
            'issues' => $issues,
            'status' => $status,
            'counts' => collect(self::ISSUE_STATUSES)->mapWithKeys(fn (string $issueStatus): array => [$issueStatus => (int) ($counts[$issueStatus] ?? 0)]),
        ]);
    }
}
