<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\MedicalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DoctorAccessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Written out here (not read from DoctorAccess) so any change to the agreed lists fails a test.
     */
    private const ASSISTANT_ALLOWED = [
        'view_appointments', 'book_appointments', 'confirm_appointments', 'reject_appointments', 'reschedule_appointments',
        'cancel_appointments', 'manage_schedule', 'manage_slots', 'view_patients', 'view_medical_documents',
        'preview_medical_documents', 'download_medical_documents',
    ];

    private const ASSISTANT_DENIED = [
        'start_appointments', 'complete_appointments', 'edit_doctor_profile', 'manage_doctor_account',
        'manage_google_calendar', 'upload_medical_documents', 'delete_medical_documents',
    ];

    /**
     * The doctor's permissions: everything in their workspace, including staff cancellation (Phase 6), except
     * uploading or deleting patient documents and account management (authorized elsewhere).
     */
    private const DOCTOR_ALLOWED = [
        'view_appointments', 'book_appointments', 'confirm_appointments', 'reject_appointments', 'reschedule_appointments',
        'cancel_appointments', 'start_appointments', 'complete_appointments', 'manage_schedule', 'manage_slots', 'view_patients',
        'view_medical_documents', 'preview_medical_documents', 'download_medical_documents', 'edit_doctor_profile',
        'manage_google_calendar',
    ];

    private DoctorAccess $access;

    private User $doctorA;

    private DoctorProfile $profileA;

    private User $doctorB;

    private DoctorProfile $profileB;

    private User $assistant;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
        $this->access = app(DoctorAccess::class);
        [$this->doctorA, $this->profileA] = $this->doctor();
        [$this->doctorB, $this->profileB] = $this->doctor();
        $this->assistant = $this->assistant([$this->profileA]);
    }

    public function test_the_agreed_ability_lists_are_complete_and_disjoint(): void
    {
        $this->assertEqualsCanonicalizing(DoctorAccess::ABILITIES, [...self::ASSISTANT_ALLOWED, ...self::ASSISTANT_DENIED]);
        $this->assertEqualsCanonicalizing(self::ASSISTANT_ALLOWED, DoctorAccess::ASSISTANT_ABILITIES);
        $this->assertEqualsCanonicalizing(self::DOCTOR_ALLOWED, DoctorAccess::DOCTOR_ABILITIES);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abilities(): array
    {
        return collect([...self::ASSISTANT_ALLOWED, ...self::ASSISTANT_DENIED])->mapWithKeys(fn (string $ability) => [$ability => [$ability]])->all();
    }

    #[DataProvider('abilities')]
    public function test_each_ability_for_every_kind_of_user(string $ability): void
    {
        $unassigned = $this->assistant([]);
        $deactivatedAssistant = $this->assistant([$this->profileA], ['deactivated_at' => now()]);
        $patient = User::factory()->create(['role' => 'patient']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertSame(in_array($ability, self::ASSISTANT_ALLOWED, true), $this->access->canActFor($this->assistant, $this->profileA, $ability), 'assigned assistant');
        $this->assertSame(in_array($ability, self::DOCTOR_ALLOWED, true), $this->access->canActFor($this->doctorA, $this->profileA, $ability), 'own doctor');

        foreach (['assistant for an unassigned doctor' => [$this->assistant, $this->profileB], 'doctor for another doctor' => [$this->doctorA, $this->profileB],
            'unassigned assistant' => [$unassigned, $this->profileA], 'deactivated assistant' => [$deactivatedAssistant, $this->profileA],
            'patient' => [$patient, $this->profileA], 'admin' => [$admin, $this->profileA]] as $case => [$user, $profile]) {
            $this->assertFalse($this->access->canActFor($user, $profile, $ability), $case);
        }
    }

    public function test_acting_profiles_for_every_role(): void
    {
        [$deactivatedDoctor, $deactivatedProfile] = $this->doctor(['deactivated_at' => now()]);
        $multi = $this->assistant([$this->profileA, $this->profileB, $deactivatedProfile]);

        $this->assertSame([$this->profileA->id], $this->access->actingProfileIds($this->doctorA));
        $this->assertSame([$this->profileA->id], $this->access->actingProfileIds($this->assistant));
        $this->assertSame([$this->profileA->id, $this->profileB->id], $this->access->actingProfileIds($multi), 'Deactivated doctors are excluded.');
        $this->assertSame([], $this->access->actingProfileIds($deactivatedDoctor));
        $this->assertSame([], $this->access->actingProfileIds($this->assistant([])));
        $this->assertSame([], $this->access->actingProfileIds(User::factory()->create(['role' => 'patient'])));
        $this->assertSame([], $this->access->actingProfileIds(User::factory()->create(['role' => 'admin'])));
        $this->assertSame([], $this->access->profileIdsFor($this->assistant, 'start_appointments'));
        $this->assertFalse($this->access->canActFor($this->assistant, $this->profileA, 'unknown_ability'));
        $this->assertFalse($this->access->canActFor($this->assistant, 0, 'view_appointments'));
        $this->assertFalse($this->access->canActFor($this->assistant, 999999, 'view_appointments'));
    }

    public function test_access_changes_take_effect_on_the_very_next_check(): void
    {
        $assistant = $this->assistant;
        $check = fn () => $this->access->canActFor($assistant, $this->profileA, 'view_appointments');
        $this->assertTrue($check());

        $this->profileA->assistants()->detach($assistant->id);
        $this->assertFalse($check(), 'Unassigned: revoked immediately.');
        $this->profileA->assistants()->attach($assistant->id);
        $this->assertTrue($check());

        $this->doctorA->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($check(), 'Doctor deactivated: closed to assistants immediately.');
        $this->assertFalse($this->access->canActFor($this->doctorA->fresh(), $this->profileA, 'view_appointments'));
        $this->doctorA->forceFill(['deactivated_at' => null])->save();
        $this->assertTrue($check());

        User::whereKey($assistant->id)->update(['deactivated_at' => now()]);
        $this->assertFalse($check(), 'The assistant is checked in the database, even with a stale in-memory model.');
        User::whereKey($assistant->id)->update(['deactivated_at' => null, 'role' => 'patient']);
        $this->assertFalse($check(), 'A role change in the database is honoured even with a stale in-memory model.');
    }

    public function test_appointment_policy_matrix(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $mine = $this->appointment($this->profileA, $patient, 'booked');
        $other = $this->appointment($this->profileB, $patient, 'booked');
        $admin = User::factory()->create(['role' => 'admin']);
        $expect = [
            'view' => ['assistant' => true, 'doctor' => true, 'patient' => true, 'admin' => true],
            'confirm' => ['assistant' => true, 'doctor' => true, 'patient' => false, 'admin' => false],
            'reject' => ['assistant' => true, 'doctor' => true, 'patient' => false, 'admin' => false],
            'reschedule' => ['assistant' => true, 'doctor' => true, 'patient' => false, 'admin' => false],
            'cancel' => ['assistant' => true, 'doctor' => true, 'patient' => true, 'admin' => false],
            'start' => ['assistant' => false, 'doctor' => true, 'patient' => false, 'admin' => false],
            'complete' => ['assistant' => false, 'doctor' => true, 'patient' => false, 'admin' => false],
        ];
        $users = ['assistant' => $this->assistant, 'doctor' => $this->doctorA, 'patient' => $patient, 'admin' => $admin];

        foreach ($expect as $ability => $byUser) {
            foreach ($byUser as $who => $allowed) {
                $this->assertSame($allowed, $users[$who]->can($ability, $mine), "{$who} {$ability} on own doctor's appointment");
            }
            $this->assertFalse($this->assistant->can($ability, $other), "assistant {$ability} on unassigned doctor's appointment");
            $this->assertFalse($this->doctorA->can($ability, $other), "doctor {$ability} on another doctor's appointment");
        }

        $this->assertTrue($patient->can('create', Appointment::class));
        $this->assertTrue($this->doctorA->can('create', Appointment::class));
        $this->assertFalse($this->assistant->can('create', Appointment::class), 'Assistants book per doctor, via DoctorProfilePolicy.');
        $this->assertFalse($this->assistant->can('viewAny', Appointment::class), 'Assistant lists are authorized per doctor.');
    }

    public function test_slot_availability_and_doctor_profile_policies(): void
    {
        $slotA = AppointmentSlot::create(['doctor_profile_id' => $this->profileA->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(10), 'status' => 'available']);
        $slotB = AppointmentSlot::create(['doctor_profile_id' => $this->profileB->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(10), 'status' => 'available']);
        $availabilityA = DoctorAvailability::create(['doctor_profile_id' => $this->profileA->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 10]);
        $availabilityB = DoctorAvailability::create(['doctor_profile_id' => $this->profileB->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 10]);

        foreach (['block', 'unblock'] as $ability) {
            $this->assertTrue($this->assistant->can($ability, $slotA));
            $this->assertFalse($this->assistant->can($ability, $slotB));
            $this->assertTrue($this->doctorA->can($ability, $slotA));
            $this->assertFalse($this->doctorA->can($ability, $slotB));
        }
        foreach (['update', 'delete'] as $ability) {
            $this->assertTrue($this->assistant->can($ability, $availabilityA));
            $this->assertFalse($this->assistant->can($ability, $availabilityB));
            $this->assertTrue($this->doctorA->can($ability, $availabilityA));
            $this->assertFalse($this->doctorA->can($ability, $availabilityB));
        }
        $this->assertTrue($this->doctorA->can('create', DoctorAvailability::class));
        $this->assertFalse($this->assistant->can('create', DoctorAvailability::class), 'Assistants create per doctor, via DoctorProfilePolicy.');

        foreach (['viewAppointments', 'bookAppointments', 'manageSchedule', 'manageSlots', 'viewPatients', 'viewMedicalDocuments'] as $ability) {
            $this->assertTrue($this->assistant->can($ability, $this->profileA), $ability);
            $this->assertFalse($this->assistant->can($ability, $this->profileB), $ability);
            $this->assertTrue($this->doctorA->can($ability, $this->profileA), $ability);
            $this->assertFalse($this->doctorA->can($ability, $this->profileB), $ability);
            $this->assertFalse(User::factory()->create(['role' => 'admin'])->can($ability, $this->profileA), "admin {$ability}");
        }
        foreach (['view', 'update', 'manageAccount'] as $ability) {
            $this->assertFalse($this->assistant->can($ability, $this->profileA), "assistants cannot {$ability} the doctor profile");
        }
        $this->assertTrue($this->doctorA->can('update', $this->profileA));
        $this->assertFalse($this->assistant->can('browseDirectory', DoctorProfile::class));
        $this->assertFalse($this->assistant->can('viewAny', DoctorProfile::class));
    }

    public function test_patients_and_admins_keep_their_permissions_and_cannot_act_as_doctors(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->appointment($this->profileA, $patient, 'booked');

        foreach (DoctorAccess::ABILITIES as $ability) {
            $this->assertFalse($this->access->canActFor($patient, $this->profileA, $ability));
            $this->assertFalse($this->access->canActFor($admin, $this->profileA, $ability));
        }
        $this->assertTrue($admin->can('view', $appointment));
        $this->assertTrue($admin->can('update', $this->profileA));
        $this->assertTrue($admin->can('manageAccount', $this->profileA));
        $this->assertTrue($admin->can('browseDirectory', DoctorProfile::class));
        $this->assertTrue($patient->can('cancel', $appointment));
        $this->assertTrue($patient->can('browseDirectory', DoctorProfile::class));

        $this->actingAs($patient)->post(route('doctor.appointments.confirm', $appointment))->assertForbidden();
    }

    public function test_patient_visibility_follows_any_appointment_with_an_assigned_doctor(): void
    {
        $seen = User::factory()->create(['role' => 'patient']);
        $cancelledOnly = User::factory()->create(['role' => 'patient']);
        $othersPatient = User::factory()->create(['role' => 'patient']);
        $this->appointment($this->profileA, $seen, 'confirmed');
        $this->appointment($this->profileA, $cancelledOnly, 'cancelled');
        $this->appointment($this->profileB, $othersPatient, 'confirmed');

        $this->assertTrue($this->access->canViewPatient($this->assistant, $seen, $this->profileA));
        $this->assertTrue($this->access->canViewPatient($this->assistant, $cancelledOnly, $this->profileA), 'Same rule as the doctor patient list.');
        $this->assertFalse($this->access->canViewPatient($this->assistant, $othersPatient, $this->profileA));
        $this->assertFalse($this->access->canViewPatient($this->assistant, $othersPatient, $this->profileB));
        $this->assertFalse($this->access->canViewPatient($this->assistant, $this->doctorB, $this->profileA), 'Only patients.');
        $this->assertTrue($this->access->canViewPatient($this->doctorA, $seen, $this->profileA));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function appointmentStatuses(): array
    {
        return ['booked' => ['booked', true], 'confirmed' => ['confirmed', true], 'completed' => ['completed', true], 'cancelled' => ['cancelled', false], 'rejected' => ['rejected', false]];
    }

    #[DataProvider('appointmentStatuses')]
    public function test_medical_document_access_follows_the_care_relationship(string $status, bool $granted): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointment($this->profileA, $patient, $status);
        $image = $this->document($patient, 'scan.png', $this->pngBytes());
        $pdf = $this->document($patient, 'report.pdf', "%PDF-1.4\n%%EOF\n");

        // Doctors are checked without a context; assistants only ever inside Doctor A's workspace.
        foreach ([[$this->assistant, $this->profileA], [$this->doctorA, null]] as [$user, $context]) {
            $this->assertSame($granted, $user->can('view', [$image, $context]));
            $this->assertSame($granted, $user->can('download', [$pdf, $context]));
            $this->assertSame($granted, $user->can('preview', [$image, $context]));
            $this->assertFalse($user->can('preview', [$pdf, $context]), 'PDFs are never previewed.');
            $this->assertFalse($user->can('delete', $image));
        }
        $this->assertFalse($this->assistant->can('download', $pdf), 'Assistants need a doctor context.');
        $this->assertFalse($this->assistant->can('create', MedicalDocument::class), 'Assistants never upload.');
        $this->assertFalse($this->doctorB->can('view', $image));
        $this->assertFalse($this->assistant([$this->profileB])->can('view', [$image, $this->profileB]));
        $this->assertTrue($this->assistant->can('viewAny', MedicalDocument::class));
    }

    public function test_document_access_is_revoked_immediately_and_downloads_are_audited_to_the_assistant(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointment($this->profileA, $patient, 'confirmed');
        $document = $this->document($patient, 'report.pdf', "%PDF-1.4\n%%EOF\n");

        $download = route('assistant.workspace.medical-documents.download', [$this->profileA, $document]);

        $this->actingAs($this->assistant)->get($download)->assertOk();
        $this->assertSame($this->assistant->id, MedicalDocumentEvent::query()->where('action', 'downloaded')->sole()->actor_id);
        $this->actingAs($this->assistant)->delete(route('medical-documents.destroy', $document))->assertForbidden();
        $this->assertModelExists($document);

        $this->profileA->assistants()->detach($this->assistant->id);
        $this->actingAs($this->assistant)->get($download)->assertForbidden();
        $this->actingAs($this->assistant)->get(route('medical-documents.download', $document))->assertForbidden();
        $this->assertSame(1, MedicalDocumentEvent::query()->where('action', 'downloaded')->count());
    }

    public function test_login_sends_each_role_to_its_own_dashboard_and_unknown_roles_nowhere(): void
    {
        foreach (['patient' => 'patient.dashboard', 'doctor' => 'doctor.dashboard', 'admin' => 'admin.dashboard'] as $role => $route) {
            $user = User::factory()->create(['role' => $role, 'password' => 'Secret-pass-1']);
            if ($role === 'doctor') {
                DoctorProfile::create(['user_id' => $user->id, 'professional_title' => 'GP', 'license_number' => 'LIC-L-'.$user->id, 'consultation_fee' => 10]);
            }
            $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret-pass-1'])->assertRedirect(route($route));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
            $this->flushSession();
        }

        $assistant = User::factory()->create(['password' => 'Secret-pass-1']);
        $assistant->forceFill(['role' => 'assistant'])->save();
        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Secret-pass-1'])
            ->assertRedirect(route('assistant.dashboard'));
        $this->assertAuthenticatedAs($assistant);
        $this->assertSame('assistant.dashboard', AuthenticatedSessionController::dashboardRoute('assistant'));

        foreach (['guest', 'superuser', '', null] as $role) {
            $this->assertNull(AuthenticatedSessionController::dashboardRoute($role));
        }
    }

    public function test_a_signed_in_assistant_reaches_only_their_own_dashboard_from_the_home_page(): void
    {
        $this->actingAs($this->assistant)->get(route('home'))->assertRedirect(route('assistant.dashboard'));

        foreach (['patient.dashboard', 'doctor.dashboard', 'admin.dashboard', 'doctor.appointments.index', 'doctor.schedule', 'doctor.medical-records.index'] as $route) {
            $this->actingAs($this->assistant)->get(route($route))->assertForbidden();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(array $attributes = []): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...$attributes]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return [$doctor, $profile];
    }

    /**
     * @param  list<DoctorProfile>  $profiles
     * @param  array<string, mixed>  $attributes
     */
    private function assistant(array $profiles, array $attributes = []): User
    {
        $assistant = User::factory()->create($attributes);
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        foreach ($profiles as $profile) {
            $profile->assistants()->attach($assistant->id);
        }

        return $assistant->fresh();
    }

    private function appointment(DoctorProfile $profile, User $patient, string $status): Appointment
    {
        $startsAt = now()->startOfHour()->addDays(2)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked',
        ]);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function document(User $patient, string $name, string $content): MedicalDocument
    {
        return app(MedicalDocumentService::class)->store($patient, UploadedFile::fake()->createWithContent($name, $content), ['category' => 'other'])->fresh();
    }

    private function pngBytes(): string
    {
        $raw = '';
        for ($row = 0; $row < 8; $row++) {
            $raw .= "\0".str_repeat("\x16\x7d\x91", 8);
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 8, 8, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }
}
