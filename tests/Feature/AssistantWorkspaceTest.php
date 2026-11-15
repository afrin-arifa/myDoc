<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use App\Services\MedicalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 5: the Doctor Assistant workspace through its real routes, as a signed-in assistant.
 */
class AssistantWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private User $doctorA;

    private DoctorProfile $profileA;

    private User $doctorB;

    private DoctorProfile $profileB;

    private User $doctorC;

    private DoctorProfile $profileC;

    private User $patient;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        Storage::fake(MedicalDocumentService::DISK);
        [$this->doctorA, $this->profileA] = $this->doctor('Dr. Alpha Assigned');
        [$this->doctorB, $this->profileB] = $this->doctor('Dr. Beta Assigned');
        [$this->doctorC, $this->profileC] = $this->doctor('Dr. Gamma Unassigned');
        $this->assistant = User::factory()->create(['name' => 'Asha Assistant']);
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profileA->assistants()->attach($this->assistant->id);
        $this->profileB->assistants()->attach($this->assistant->id);
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Pat Patient']);
    }

    public function test_dashboard_lists_only_assigned_doctors_with_their_upcoming_appointments(): void
    {
        $this->appointment($this->profileA, 'booked');
        $this->appointment($this->profileB, 'confirmed');
        $this->appointment($this->profileC, 'booked', User::factory()->create(['role' => 'patient', 'name' => 'Hidden Patient']));

        $page = $this->actingAs($this->assistant)->get(route('assistant.dashboard'))->assertOk();

        $page->assertSee('Welcome, Asha Assistant')->assertSee('You work for 2 doctors')
            ->assertSee('Dr. Alpha Assigned')->assertSee('Dr. Beta Assigned')
            ->assertDontSee('Dr. Gamma Unassigned')->assertDontSee('Hidden Patient')
            ->assertSee(route('assistant.workspace.appointments.index', $this->profileA), false);
        $page->assertViewHas('doctors', fn ($doctors) => $doctors->firstWhere('id', $this->profileA->id)->pending_count === 1
            && $doctors->firstWhere('id', $this->profileB->id)->upcoming_count === 1);
        $page->assertViewHas('upcoming', fn ($upcoming) => $upcoming->count() === 2);

        $this->actingAs($this->assistant)->get(route('assistant.doctors.index'))->assertOk()
            ->assertSee('Dr. Alpha Assigned')->assertSee('Dr. Beta Assigned')->assertDontSee('Dr. Gamma Unassigned');
    }

    public function test_an_assistant_without_assignments_sees_an_empty_state(): void
    {
        $lonely = User::factory()->create();
        $lonely->forceFill(['role' => User::ROLE_ASSISTANT])->save();

        $this->actingAs($lonely)->get(route('assistant.dashboard'))->assertOk()
            ->assertSee('No doctors assigned yet')->assertDontSee('Dr. Alpha')->assertDontSee('Dr. Gamma');
        $this->actingAs($lonely)->get(route('assistant.workspace.appointments.index', $this->profileA))->assertForbidden();
    }

    public function test_the_switcher_offers_only_assigned_doctors_and_access_is_rechecked_on_every_request(): void
    {
        $page = $this->actingAs($this->assistant)->get(route('assistant.workspace.schedule', $this->profileA))->assertOk();
        $page->assertSee('Working for')->assertSee('data-selected-doctor>Dr. Alpha Assigned', false)
            ->assertSee(route('assistant.workspace.schedule', $this->profileB), false)
            ->assertDontSee(route('assistant.workspace.schedule', $this->profileC), false);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.schedule', $this->profileB))->assertOk()->assertSee('data-selected-doctor>Dr. Beta Assigned', false);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.show', $this->profileA))->assertRedirect(route('assistant.workspace.appointments.index', $this->profileA));

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', $this->profileC))->assertForbidden();
        $this->actingAs($this->assistant)->get('/assistant/doctors/999999/appointments')->assertNotFound();

        $this->profileA->assistants()->detach($this->assistant->id);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', $this->profileA))->assertForbidden();

        $this->doctorB->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', $this->profileB))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('assistant.dashboard'))->assertOk()->assertSee('No doctors assigned yet');

        $this->doctorB->forceFill(['deactivated_at' => null])->save();
        $this->assistant->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($this->assistant->fresh())->get(route('assistant.workspace.appointments.index', $this->profileB))->assertForbidden();
        $this->actingAs($this->assistant->fresh())->get(route('assistant.dashboard'))->assertForbidden();
    }

    public function test_appointment_lists_and_details_stay_inside_the_selected_doctor(): void
    {
        $mine = $this->appointment($this->profileA, 'booked');
        $theirs = $this->appointment($this->profileB, 'booked', User::factory()->create(['role' => 'patient', 'name' => 'Beta Only Patient']));

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', $this->profileA))->assertOk()
            ->assertViewHas('appointments', fn ($appointments) => $appointments->pluck('id')->all() === [$mine->id])
            ->assertDontSee('Beta Only Patient');
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', [$this->profileA, 'search' => 'Beta Only']))->assertOk()
            ->assertViewHas('appointments', fn ($appointments) => $appointments->isEmpty());

        $show = $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileA, $mine]))->assertOk();
        $show->assertSee(route('assistant.workspace.appointments.confirm', [$this->profileA, $mine]), false)
            ->assertDontSee('Mark completed')->assertDontSee('/doctor/appointments', false);

        $confirmed = $this->appointment($this->profileA, 'confirmed');
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileA, $confirmed]))->assertOk()
            ->assertSee(route('assistant.workspace.appointments.reschedule', [$this->profileA, $confirmed]), false)
            ->assertDontSee('Mark completed')->assertDontSee('/complete', false)->assertDontSee('/start', false);

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileA, $theirs]))->assertNotFound();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileB, $theirs]))->assertOk();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileC, $this->appointment($this->profileC, 'booked')]))->assertForbidden();
    }

    public function test_pagination_keeps_the_selected_doctor(): void
    {
        foreach (range(1, 11) as $number) {
            $this->appointment($this->profileA, 'booked');
        }

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.index', [$this->profileA, 'tab' => 'all']))->assertOk()
            ->assertSee(e(route('assistant.workspace.appointments.index', [$this->profileA, 'tab' => 'all', 'page' => 2])), false);
    }

    public function test_assistant_books_confirms_rejects_and_reschedules_as_themselves(): void
    {
        $toConfirm = $this->appointment($this->profileA, 'booked');
        $toReject = $this->appointment($this->profileA, 'booked');
        $toReschedule = $this->appointment($this->profileA, 'confirmed');
        $free = $this->slot($this->profileA, daysAhead: 5);
        $newSlot = $this->slot($this->profileA, daysAhead: 6);

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.create', [$this->profileA, 'date' => $free->starts_at->toDateString()]))->assertOk()
            ->assertSee('value="'.$free->id.'"', false);
        $this->actingAs($this->assistant)->getJson(route('assistant.workspace.patients.search', [$this->profileA, 'search' => 'Pat']))->assertOk()
            ->assertJsonPath('data.0.id', $this->patient->id);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.store', $this->profileA), [
            'patient_id' => $this->patient->id, 'slot_id' => $free->id, 'consultation_type' => 'physical',
        ])->assertRedirect(route('assistant.workspace.schedule', $this->profileA));
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.confirm', [$this->profileA, $toConfirm]))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reject', [$this->profileA, $toReject]))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.reschedule', [$this->profileA, $toReschedule]))->assertOk()->assertSee('value="'.$newSlot->id.'"', false);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reschedule.store', [$this->profileA, $toReschedule]), ['slot_id' => $newSlot->id, 'reason' => 'Clinic closed'])
            ->assertRedirect(route('assistant.workspace.appointments.show', [$this->profileA, $toReschedule]));

        $this->assertSame('confirmed', $toConfirm->fresh()->status);
        $this->assertSame('rejected', $toReject->fresh()->status);
        $this->assertSame($newSlot->id, $toReschedule->fresh()->appointment_slot_id);
        $this->assertSame('booked', $free->fresh()->status);
        $this->assertSame($this->assistant->id, AppointmentRescheduleHistory::sole()->performed_by);

        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertSame(['booked', 'confirmed', 'rejected', 'rescheduled'], $events->pluck('action')->all());
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->assistant->id && $event->actor_role === 'assistant' && $event->doctor_profile_id === $this->profileA->id));
        $this->assertSame(0, DoctorWorkspaceEvent::where('actor_id', $this->doctorA->id)->count());
        $this->assertDatabaseHas('appointment_notifications', ['appointment_id' => $toConfirm->id, 'channel' => 'email', 'type' => 'confirmed', 'recipient_id' => $this->patient->id]);
        $this->assertSame(0, AppointmentNotification::where('recipient_id', $this->assistant->id)->count(), 'No assistant notifications.');
    }

    public function test_assistants_cannot_start_complete_use_the_patient_cancel_route_or_reach_another_doctor(): void
    {
        $confirmed = $this->appointment($this->profileA, 'confirmed', startsAt: now()->addMinutes(5));
        $theirs = $this->appointment($this->profileC, 'booked');

        foreach (['start', 'complete'] as $action) {
            $this->assertFalse(Route::has("assistant.workspace.appointments.{$action}"), "No assistant {$action} route.");
        }
        $this->actingAs($this->assistant)->post(route('appointments.cancel', $confirmed))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('doctor.appointments.start', $confirmed))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('doctor.appointments.complete', $confirmed))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.confirm', [$this->profileA, $theirs]))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.confirm', [$this->profileC, $theirs]))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.store', $this->profileA), [
            'patient_id' => $this->patient->id, 'slot_id' => $this->slot($this->profileC)->id, 'consultation_type' => 'physical',
        ])->assertSessionHasErrors();

        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('booked', $theirs->fresh()->status);
        $this->assertSame(0, DoctorWorkspaceEvent::count(), 'Refused actions record nothing.');
    }

    public function test_failed_actions_record_no_event(): void
    {
        $confirmed = $this->appointment($this->profileA, 'confirmed');

        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.confirm', [$this->profileA, $confirmed]))->assertSessionHasErrors('status');
        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.block', [$this->profileA, $confirmed->slot]))->assertSessionHasErrors('slot');

        $this->assertSame(0, DoctorWorkspaceEvent::count());
    }

    public function test_assistant_manages_schedule_availability_and_slots_for_the_selected_doctor(): void
    {
        $page = $this->actingAs($this->assistant)->get(route('assistant.workspace.schedule', $this->profileA))->assertOk();
        $this->assertStringNotContainsString(url('/doctor/'), $page->getContent(), 'No link leaves the assistant workspace for a doctor-only page.');
        $page->assertDontSee('Account settings');

        $this->actingAs($this->assistant)->post(route('assistant.workspace.availability.store', $this->profileA), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => '1'])->assertSessionHasNoErrors();
        $availability = DoctorAvailability::sole();
        $this->assertSame($this->profileA->id, $availability->doctor_profile_id);
        $this->actingAs($this->assistant)->patch(route('assistant.workspace.availability.update', [$this->profileA, $availability]), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'is_active' => '1'])->assertSessionHasNoErrors();

        $start = now()->addDays(8)->toDateString();
        $end = now()->addDays(14)->toDateString();
        $this->actingAs($this->assistant)->postJson(route('assistant.workspace.slots.preview', $this->profileA), ['start_date' => $start, 'end_date' => $end, 'weekdays' => [1]])->assertOk()->assertJsonStructure(['slots', 'total']);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.generate', $this->profileA), ['start_date' => $start, 'end_date' => $end, 'weekdays' => [1]])->assertSessionHasNoErrors();
        $slot = AppointmentSlot::where('doctor_profile_id', $this->profileA->id)->orderBy('starts_at')->first();
        $this->assertNotNull($slot);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.block', [$this->profileA, $slot]))->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->delete(route('assistant.workspace.slots.unblock', [$this->profileA, $slot]))->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->delete(route('assistant.workspace.availability.destroy', [$this->profileA, $availability]))->assertSessionHasNoErrors();

        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertSame(['availability_created', 'availability_updated', 'slots_generated', 'slot_blocked', 'slot_unblocked', 'availability_deleted'], $events->pluck('action')->all());
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->assistant->id && $event->actor_role === 'assistant' && $event->doctor_profile_id === $this->profileA->id));
        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_other_doctors_slots_and_availability_cannot_be_changed(): void
    {
        $slotC = $this->slot($this->profileC);
        $slotB = $this->slot($this->profileB);
        $availabilityB = $this->profileB->availabilities()->create(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 10, 'is_active' => true]);

        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.block', [$this->profileC, $slotC]))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.block', [$this->profileA, $slotC]))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.slots.block', [$this->profileA, $slotB]))->assertNotFound();
        $this->actingAs($this->assistant)->patch(route('assistant.workspace.availability.update', [$this->profileA, $availabilityB]), ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '10:00'])->assertForbidden();
        $this->actingAs($this->assistant)->delete(route('assistant.workspace.availability.destroy', [$this->profileA, $availabilityB]))->assertNotFound();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.availability.store', $this->profileC), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'])->assertForbidden();

        $this->assertSame('available', $slotC->fresh()->status);
        $this->assertSame('available', $slotB->fresh()->status);
        $this->assertModelExists($availabilityB);
        $this->assertSame(0, DoctorWorkspaceEvent::count());
    }

    public function test_patients_and_medical_records_follow_the_selected_doctor_and_care_rules(): void
    {
        $patientA = $this->patientWith($this->profileA, 'confirmed', 'Alice Of Alpha');
        $patientB = $this->patientWith($this->profileB, 'completed', 'Bob Of Beta');
        $patientC = $this->patientWith($this->profileC, 'confirmed', 'Carol Of Gamma');
        $cancelledOnly = $this->patientWith($this->profileA, 'cancelled', 'Cancelled Only');
        $docA = $this->document($patientA, 'alpha-report.pdf');
        $docB = $this->document($patientB, 'beta-report.pdf');
        $docC = $this->document($patientC, 'gamma-report.pdf');
        $docCancelled = $this->document($cancelledOnly, 'cancelled-report.pdf');

        $this->actingAs($this->assistant)->get(route('assistant.workspace.patients.index', $this->profileA))->assertOk()
            ->assertSee('Alice Of Alpha')->assertSee('Cancelled Only')->assertDontSee('Bob Of Beta')->assertDontSee('Carol Of Gamma');

        $records = $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profileA))->assertOk();
        $records->assertSee('alpha-report.pdf')->assertDontSee('beta-report.pdf')->assertDontSee('gamma-report.pdf')->assertDontSee('cancelled-report.pdf')
            ->assertDontSee('btn-outline-danger', false)->assertSee(route('assistant.workspace.medical-documents.download', [$this->profileA, $docA]), false)
            ->assertDontSee(route('medical-documents.download', $docA).'"', false);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profileB))->assertOk()->assertSee('beta-report.pdf');
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profileC))->assertForbidden();

        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-documents.download', [$this->profileA, $docA]))->assertOk();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-documents.download', [$this->profileA, $docB]))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-documents.download', [$this->profileA, $docC]))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-documents.download', [$this->profileC, $docC]))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-documents.download', [$this->profileA, $docCancelled]))->assertForbidden();
        $this->actingAs($this->assistant)->get(route('medical-documents.download', $docA))->assertForbidden();
        $this->actingAs($this->assistant)->delete(route('medical-documents.destroy', $docA))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('patient.documents.store'), ['category' => 'other', 'document' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF\n")])->assertForbidden();

        $this->assertSame($this->assistant->id, MedicalDocumentEvent::where('action', 'downloaded')->sole()->actor_id);
        $this->assertSame(4, MedicalDocument::count());
        $this->assertModelExists($docB);
    }

    public function test_doctor_only_admin_and_patient_pages_stay_closed_to_assistants(): void
    {
        foreach (['doctor.dashboard', 'doctor.profile', 'doctor.settings', 'doctor.appointments.index', 'doctor.schedule', 'admin.dashboard', 'admin.assistants.index', 'patient.dashboard'] as $name) {
            $this->actingAs($this->assistant)->get(route($name))->assertForbidden();
        }
        $this->actingAs($this->assistant)->get(route('doctor.google-calendar.connect'))->assertForbidden();
        $this->actingAs($this->assistant)->put(route('doctor.profile.update'), ['professional_title' => 'X'])->assertForbidden();
        $this->actingAs($this->assistant)->put(route('doctor.account.update'), ['name' => 'X'])->assertForbidden();
    }

    public function test_doctors_and_patients_cannot_use_the_assistant_workspace(): void
    {
        foreach ([$this->doctorA, $this->patient, User::factory()->create(['role' => 'admin'])] as $user) {
            $this->flushSession();
            $this->actingAs($user)->get(route('assistant.dashboard'))->assertForbidden();
            $this->actingAs($user)->get(route('assistant.workspace.appointments.index', $this->profileA))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get(route('assistant.dashboard'))->assertRedirect(route('login'));
    }

    public function test_doctor_pages_keep_their_own_links_and_controls(): void
    {
        $appointment = $this->appointment($this->profileA, 'confirmed');

        $this->actingAs($this->doctorA)->get(route('doctor.appointments.show', $appointment))->assertOk()
            ->assertSee(route('doctor.appointments.complete', $appointment), false)->assertDontSee('/assistant/', false);
        $this->actingAs($this->doctorA)->get(route('doctor.schedule'))->assertOk()->assertSee('Account settings')->assertDontSee('/assistant/', false);
        $this->actingAs($this->doctorA)->get(route('doctor.appointments.index'))->assertOk()->assertSee(route('doctor.dashboard'), false);
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return [$doctor, $profile];
    }

    private function slot(DoctorProfile $profile, int $daysAhead = 4, string $status = 'available', mixed $startsAt = null): AppointmentSlot
    {
        $startsAt = $startsAt ?? now()->startOfHour()->addDays($daysAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, string $status, ?User $patient = null, mixed $startsAt = null): Appointment
    {
        $slot = $this->slot($profile, status: in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked', startsAt: $startsAt);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => ($patient ?? $this->patient)->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function patientWith(DoctorProfile $profile, string $status, string $name): User
    {
        $patient = User::factory()->create(['role' => 'patient', 'name' => $name]);
        $this->appointment($profile, $status, $patient);

        return $patient;
    }

    private function document(User $patient, string $name): MedicalDocument
    {
        return DB::transaction(fn () => app(MedicalDocumentService::class)->store($patient, UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n"), ['category' => 'other']))->fresh();
    }
}
