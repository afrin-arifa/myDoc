<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminDoctorRequest;
use App\Http\Requests\Admin\UpdateAdminDoctorRequest;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Services\DoctorOnboardingService;
use App\Services\DoctorPhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminDoctorController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        DoctorOnboardingService::STATUS_ACTIVE => 'Active',
        DoctorOnboardingService::STATUS_INVITATION_PENDING => 'Invitation pending',
        DoctorOnboardingService::STATUS_INVITATION_EXPIRED => 'Invitation expired',
        DoctorOnboardingService::STATUS_DEACTIVATED => 'Deactivated',
    ];

    public function __construct(private readonly DoctorOnboardingService $onboarding) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', DoctorProfile::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUS_LABELS))],
        ]);
        $search = $filters['search'] ?? null;

        $query = DoctorProfile::query()
            ->with(['user:id,name,email,deactivated_at', 'specialties:id,name'])
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('professional_title', 'like', "%{$search}%")
                ->orWhere('license_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($user) => $user
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))));

        if (isset($filters['status'])) {
            $this->onboarding->applyStatusFilter($query, $filters['status']);
        }

        $doctors = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.doctors.index', [
            'doctors' => $doctors,
            'statuses' => $this->onboarding->statusesFor($doctors->getCollection()),
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', DoctorProfile::class);

        return view('admin.doctors.create', [
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
            'invitationHours' => intdiv($this->onboarding->expiryMinutes(), 60),
        ]);
    }

    public function store(StoreAdminDoctorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        ['doctor' => $doctor, 'invitation' => $invitation] = $this->onboarding->createDoctor(
            ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null],
            [
                ...$request->safe()->only(['professional_title', 'license_number', 'consultation_fee', 'years_of_experience', 'qualifications', 'bio']),
                'is_accepting_patients' => $request->boolean('is_accepting_patients'),
            ],
            array_map('intval', $data['specialty_ids'] ?? [])
        );

        $redirect = redirect()->route('admin.doctors.show', $doctor->doctorProfile);

        return $invitation === DoctorOnboardingService::INVITATION_SENT
            ? $redirect->with('status', 'Doctor account created. An invitation has been emailed to '.$doctor->email.'.')
            : $redirect->with('status', 'Doctor account created, but the invitation email could not be sent. Use "Resend invitation" to try again.');
    }

    public function show(DoctorProfile $doctor): View
    {
        Gate::authorize('view', $doctor);
        $doctor->load(['user', 'specialties']);

        return view('admin.doctors.show', [
            'doctor' => $doctor,
            'specialties' => Specialty::where('is_active', true)->orderBy('name')->get(),
            'status' => $this->onboarding->statusFor($doctor->user),
            'statusLabels' => self::STATUS_LABELS,
            'invitationExpiresAt' => $this->onboarding->invitationExpiresAt($doctor->user),
            'appointmentCount' => $doctor->appointmentSlots()->whereHas('appointments')->count(),
        ]);
    }

    public function update(UpdateAdminDoctorRequest $request, DoctorProfile $doctor, DoctorPhotoStorage $photos): RedirectResponse
    {
        $data = $request->safe()->except(['specialty_ids', 'photo', 'remove_photo']);
        $specialtyIds = $request->validated('specialty_ids') ?? [];
        $data['is_accepting_patients'] = $request->boolean('is_accepting_patients');
        $userData = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'address']));
        $profileData = array_diff_key($data, $userData);

        $invitation = null;

        DB::transaction(function () use ($doctor, $userData, $profileData, $specialtyIds, $request, $photos, &$invitation): void {
            $previousEmail = $doctor->user->email;
            $doctor->user->update($userData);
            $this->onboarding->emailChanged($doctor->user, $previousEmail, $invitation);
            $doctor->update($profileData);
            $doctor->specialties()->sync($specialtyIds);

            if ($request->hasFile('photo')) {
                $photos->replace($doctor, $request->file('photo'));
            } elseif ($request->boolean('remove_photo')) {
                $photos->remove($doctor);
            }
        });

        return back()->with('status', 'Doctor profile updated.'.$this->invitationNotice($invitation));
    }

    /**
     * Extra flash text when an email change re-issued the doctor's invitation.
     */
    public static function invitationNotice(?string $invitation): string
    {
        return match ($invitation) {
            DoctorOnboardingService::INVITATION_SENT => ' The pending invitation was replaced and sent to the new email address; earlier links no longer work.',
            DoctorOnboardingService::INVITATION_FAILED => ' The pending invitation was replaced, but the email to the new address could not be sent. Use "Resend invitation".',
            default => '',
        };
    }

    public function updateStatus(Request $request, DoctorProfile $doctor): RedirectResponse
    {
        Gate::authorize('manageAccount', $doctor);
        $action = $request->validate(['action' => ['required', 'in:activate,deactivate']])['action'];

        if ($action === 'deactivate') {
            $this->onboarding->deactivate($doctor->user);

            return back()->with('status', 'Doctor account deactivated. Existing appointments and records are kept.');
        }

        if (! $this->onboarding->activate($doctor->user)) {
            return back()->withErrors(['status' => 'This account is waiting for the doctor to accept their invitation. Resend the invitation instead.']);
        }

        return back()->with('status', 'Doctor account activated.');
    }

    public function resendInvitation(DoctorProfile $doctor): RedirectResponse
    {
        Gate::authorize('manageAccount', $doctor);

        return match ($this->onboarding->resendInvitation($doctor->user)) {
            DoctorOnboardingService::INVITATION_SENT => back()->with('status', 'A new invitation has been emailed. Earlier invitation links no longer work.'),
            DoctorOnboardingService::INVITATION_THROTTLED => back()->withErrors(['invitation' => 'An invitation was sent moments ago. Please wait a minute before sending another.']),
            DoctorOnboardingService::INVITATION_FAILED => back()->withErrors(['invitation' => 'A new invitation was created, but the email could not be sent. Please try again shortly.']),
            default => back()->withErrors(['invitation' => 'Invitations can only be sent to doctors who have not activated their account.']),
        };
    }
}
