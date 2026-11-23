<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only overview of every doctor's waiting list for administrators. Admins do not offer or withdraw times; the
 * doctor's workspace does.
 */
class AdminWaitlistController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', WaitlistEntry::class);

        $filters = $request->validate([
            'doctor' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(WaitlistEntry::STATUS_LABELS))],
            'offer' => ['nullable', 'integer'],
        ]);
        $offerEntryId = isset($filters['offer']) ? WaitlistOffer::query()->whereKey($filters['offer'])->value('waitlist_entry_id') : null;

        return view('admin.waitlist.index', [
            'entries' => WaitlistEntry::query()
                ->with(['patient:id,name,email', 'doctorProfile.user:id,name', 'location:id,name', 'pendingOffer.slot'])
                ->when($filters['doctor'] ?? null, fn ($query, $doctorId) => $query->where('doctor_profile_id', $doctorId))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when(isset($filters['offer']), fn ($query) => $query->whereKey($offerEntryId ?? 0))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'doctors' => DoctorProfile::query()->with('user:id,name')->get()->sortBy('user.name')->values(),
            'filters' => $filters,
        ]);
    }
}
