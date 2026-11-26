<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\UpdateDoctorAccountRequest;
use App\Http\Requests\Doctor\UpdateDoctorProfileRequest;
use App\Models\Appointment;
use App\Models\DoctorAnnouncement;
use App\Models\Specialty;
use App\Services\DoctorPhotoStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorProfileController extends Controller
{
    public function show(): View
    {
        $user = request()->user();
        $profile = $user->doctorProfile?->load(['specialties', 'availabilities', 'googleCalendarConnection']);
        $appointments = $profile
            ? Appointment::query()
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $profile->id))
                ->with(['slot', 'patient:id,name,photo_path'])
                ->orderBy('appointment_slots.starts_at')
                ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                ->select('appointments.*')
                ->limit(5)
                ->get()
            : collect();
        $pendingAppointments = $profile
            ? Appointment::query()
                ->where('appointments.status', 'booked')
                ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profile->id))
                ->with(['slot', 'patient:id,name,photo_path'])
                ->orderBy('appointment_slots.starts_at')
                ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                ->select('appointments.*')
                ->limit(5)
                ->get()
            : collect();

        $today = today();
        $todaySlots = $profile
            ? $profile->appointmentSlots()
                ->whereDate('starts_at', $today)
                ->where(fn (Builder $slots) => $slots
                    ->where('starts_at', '>', now())
                    ->orWhereHas('appointments', fn (Builder $appointments) => $appointments->whereNotIn('status', Appointment::CLOSED_STATUSES)))
                ->with([
                    'appointments' => fn (HasMany $appointments) => $appointments->whereNotIn('status', Appointment::CLOSED_STATUSES),
                    'appointments.patient:id,name',
                ])
                ->orderBy('starts_at')
                ->get()
            : collect();
        $hadSlotsToday = $profile && ($todaySlots->isNotEmpty()
            || $profile->appointmentSlots()->whereDate('starts_at', $today)->exists());

        $appointmentCounts = $profile ? $this->appointmentCounts($profile->id) : ['total' => 0, 'booked' => 0, 'confirmed' => 0, 'cancelled' => 0];

        return view('doctor.dashboard', [
            'teamNotices' => $profile
                ? DoctorAnnouncement::query()->unreadForStaff($user, [$profile->id])->with('author:id,name,role')->limit(5)->get()
                : collect(),
            'profile' => $profile,
            'appointments' => $appointments,
            'pendingAppointments' => $pendingAppointments,
            'todaySlots' => $todaySlots,
            'hadSlotsToday' => $hadSlotsToday,
            'appointmentCounts' => $appointmentCounts,
            'pendingCount' => $appointmentCounts['booked'],
        ]);
    }

    public function profile(Request $request): View
    {
        $profile = $request->user()->doctorProfile?->load(['user', 'specialties']);
        abort_unless($profile, 403);

        return view('doctor.profile', [
            'profile' => $profile,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function settings(Request $request): View
    {
        $profile = $request->user()->doctorProfile?->load('googleCalendarConnection');
        abort_unless($profile, 403);

        return view('doctor.settings', [
            'profile' => $profile,
            'calendarIssueCount' => $profile->googleCalendarConnection
                ? $profile->googleCalendarConnection->appointmentEvents()->whereIn('status', ['failed', 'delete_pending'])->count()
                : 0,
        ]);
    }

    /**
     * Slots of this doctor with at least one appointment, in total and by status, in one query.
     *
     * @return array{total: int, booked: int, confirmed: int, cancelled: int}
     */
    private function appointmentCounts(int $profileId): array
    {
        $closed = implode(', ', array_fill(0, count(Appointment::CLOSED_STATUSES), '?'));
        $counts = Appointment::query()
            ->join('appointment_slots', 'appointment_slots.id', '=', 'appointments.appointment_slot_id')
            ->where('appointment_slots.doctor_profile_id', $profileId)
            ->selectRaw(
                "count(distinct appointments.appointment_slot_id) as total,
                count(distinct case when appointments.status = 'booked' then appointments.appointment_slot_id end) as booked,
                count(distinct case when appointments.status = 'confirmed' then appointments.appointment_slot_id end) as confirmed,
                count(distinct case when appointments.status in ({$closed}) then appointments.appointment_slot_id end) as cancelled",
                Appointment::CLOSED_STATUSES
            )
            ->toBase()
            ->first();

        return array_map('intval', (array) $counts);
    }

    public function update(UpdateDoctorProfileRequest $request, DoctorPhotoStorage $photos): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->safe()->except(['specialty_ids', 'photo', 'remove_photo']);
        $specialtyIds = $request->validated('specialty_ids') ?? [];
        $validated['is_accepting_patients'] = $request->boolean('is_accepting_patients');

        DB::transaction(function () use ($user, $validated, $specialtyIds, $request, $photos): void {
            $profile = $user->doctorProfile()->updateOrCreate(
                ['user_id' => $user->id],
                $validated
            );
            $profile->specialties()->sync($specialtyIds);

            if ($request->hasFile('photo')) {
                $photos->replace($profile, $request->file('photo'));
            } elseif ($request->boolean('remove_photo')) {
                $photos->remove($profile);
            }
        });

        return back()->with('status', 'Your doctor profile has been saved.');
    }

    public function updateAccount(UpdateDoctorAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('status', 'Your account details have been updated.');
    }
}
