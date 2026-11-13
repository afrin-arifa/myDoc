<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAccess;
use App\Services\DoctorAppointmentService;
use App\Services\DoctorPhotoStorage;
use App\Services\DoctorWorkspace;
use App\Services\MedicalDocumentService;
use App\Services\SlotBlockingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 3: services and HTTP entry points take an explicit doctor context, separate from the actor. Assistants are
 * exercised through the real services (their routes come in a later phase); doctors through their existing routes.
 */
class DoctorContextRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $doctorA;

    private DoctorProfile $profileA;

    private User $doctorB;

    private DoctorProfile $profileB;

    private User $doctorC;

    private DoctorProfile $profileC;

    private User $assistant;

    private User $patient;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
        [$this->doctorA, $this->profileA] = $this->doctor('Dr. Alpha');
        [$this->doctorB, $this->profileB] = $this->doctor('Dr. Beta');
        [$this->doctorC, $this->profileC] = $this->doctor('Dr. Unassigned');
        $this->assistant = $this->assistant([$this->profileA, $this->profileB]);
        $this->patient = User::factory()->create(['role' => 'patient']);
    }

    public function test_an_assigned_assistant_confirms_and_rejects_for_several_doctors(): void
    {
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $forA = $this->appointment($this->profileA, 'booked');
        $forB = $this->appointment($this->profileB, 'booked');
        $service = app(DoctorAppointmentService::class);

        $service->transition($this->assistant, $forA, 'confirmed', $this->profileA);
        $service->transition($this->assistant, $forB, 'rejected', $this->profileB);

        $this->assertSame('confirmed', $forA->fresh()->status);
        $this->assertSame('rejected', $forB->fresh()->status);
        $this->assertSame('available', $forB->slot->fresh()->status);
        $this->assertDatabaseHas('appointment_notifications', ['appointment_id' => $forA->id, 'channel' => 'email', 'type' => 'confirmed', 'recipient_id' => $this->patient->id]);
        $this->assertDatabaseHas('appointment_notifications', ['appointment_id' => $forB->id, 'channel' => 'email', 'type' => 'rejected', 'recipient_id' => $this->patient->id]);
        $this->assertSame(0, AppointmentNotification::where('recipient_id', $this->assistant->id)->count(), 'No assistant notifications yet.');
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, 2);
    }

    public function test_reschedule_records_the_real_actor_and_keeps_the_doctor_context(): void
    {
        $service = app(DoctorAppointmentService::class);
        $byAssistant = $this->appointment($this->profileA, 'confirmed');
        $byDoctor = $this->appointment($this->profileA, 'confirmed');

        $service->reschedule($this->assistant, $byAssistant, $this->slot($this->profileA)->id, 'Clinic closed', $this->profileA);
        $this->actingAs($this->doctorA)->post(route('doctor.appointments.reschedule.store', $byDoctor), ['slot_id' => $this->slot($this->profileA)->id])->assertRedirect();

        $assistantHistory = AppointmentRescheduleHistory::where('appointment_id', $byAssistant->id)->sole();
        $doctorHistory = AppointmentRescheduleHistory::where('appointment_id', $byDoctor->id)->sole();
        $this->assertSame($this->assistant->id, $assistantHistory->performed_by, 'The assistant, not the doctor, is recorded.');
        $this->assertNotSame($this->doctorA->id, $assistantHistory->performed_by);
        $this->assertSame($this->doctorA->id, $doctorHistory->performed_by);
        $this->assertSame($this->profileA->id, $byAssistant->fresh()->slot->doctor_profile_id, 'The appointment stays with the doctor.');
        $this->assertSame($this->profileA->id, $assistantHistory->newSlot->doctor_profile_id);

        $this->expectException(ValidationException::class);
        $service->reschedule($this->assistant, $byAssistant->fresh(), $this->slot($this->profileB)->id, null, $this->profileA);
    }

    public function test_an_assistant_books_and_manages_slots_for_an_assigned_doctor(): void
    {
        $free = $this->slot($this->profileA);
        $toBlock = $this->slot($this->profileA);

        $appointment = app(AppointmentBookingService::class)->bookForDoctor($this->assistant, $this->patient, $free->id, 'physical', 'Front desk booking', $this->profileA);
        app(SlotBlockingService::class)->block($this->assistant, $toBlock, $this->profileA);

        $this->assertSame($free->id, $appointment->appointment_slot_id);
        $this->assertSame($this->patient->id, $appointment->patient_id);
        $this->assertSame('booked', $free->fresh()->status);
        $this->assertSame('blocked', $toBlock->fresh()->status);

        app(SlotBlockingService::class)->unblock($this->assistant, $toBlock, $this->profileA);
        $this->assertSame('available', $toBlock->fresh()->status);
    }

    public function test_assistants_cannot_start_complete_or_act_without_a_valid_doctor_context(): void
    {
        $appointment = $this->appointment($this->profileA, 'confirmed');
        $forC = $this->appointment($this->profileC, 'booked');
        $service = app(DoctorAppointmentService::class);
        $denied = [
            'start (denied ability)' => fn () => $service->start($this->assistant, $appointment, $this->profileA),
            'complete (denied ability)' => fn () => $service->complete($this->assistant, $appointment, $this->profileA),
            'no context (an assistant has no profile of their own)' => fn () => $service->transition($this->assistant, $forC, 'confirmed'),
            'unassigned doctor' => fn () => $service->transition($this->assistant, $forC, 'confirmed', $this->profileC),
            'book for unassigned doctor' => fn () => app(AppointmentBookingService::class)->bookForDoctor($this->assistant, $this->patient, $this->slot($this->profileC)->id, 'physical', null, $this->profileC),
            'slots of unassigned doctor' => fn () => app(SlotBlockingService::class)->block($this->assistant, $this->slot($this->profileC), $this->profileC),
            'patient' => fn () => $service->transition($this->patient, $appointment, 'confirmed', $this->profileA),
            'admin' => fn () => $service->transition(User::factory()->create(['role' => 'admin']), $appointment, 'confirmed', $this->profileA),
            'doctor for another doctor' => fn () => $service->transition($this->doctorA, $forC, 'confirmed', $this->profileC),
        ];

        foreach ($denied as $case => $attempt) {
            try {
                $attempt();
                $this->fail("{$case} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertSame('booked', $forC->fresh()->status);
    }

    public function test_records_must_belong_to_the_doctor_context(): void
    {
        $forB = $this->appointment($this->profileB, 'booked');
        $slotB = $this->slot($this->profileB);

        foreach ([
            fn () => app(DoctorAppointmentService::class)->transition($this->assistant, $forB, 'confirmed', $this->profileA),
            fn () => app(SlotBlockingService::class)->block($this->assistant, $slotB, $this->profileA),
            fn () => app(DoctorAppointmentService::class)->transition($this->doctorA, $forB, 'confirmed'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A record of another doctor must not be reachable through this context.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ValidationException::class);
        app(AppointmentBookingService::class)->bookForDoctor($this->assistant, $this->patient, $slotB->id, 'physical', null, $this->profileA);
    }

    public function test_deactivation_and_unassignment_revoke_access_immediately(): void
    {
        $appointment = $this->appointment($this->profileA, 'booked');
        $confirm = fn () => app(DoctorAppointmentService::class)->transition($this->assistant, $appointment, 'confirmed', $this->profileA);
        $assertRefused = function (string $case) use ($confirm): void {
            try {
                $confirm();
                $this->fail("{$case}: access must be revoked.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        };

        $this->doctorA->forceFill(['deactivated_at' => now()])->save();
        $assertRefused('Doctor deactivated');
        $this->doctorA->forceFill(['deactivated_at' => null])->save();

        User::whereKey($this->assistant->id)->update(['deactivated_at' => now()]);
        $assertRefused('Assistant deactivated (stale in-memory model)');
        User::whereKey($this->assistant->id)->update(['deactivated_at' => null]);

        $this->profileA->assistants()->detach($this->assistant->id);
        $assertRefused('Assignment removed');
        $this->assertSame('booked', $appointment->fresh()->status);

        $this->profileA->assistants()->attach($this->assistant->id);
        $confirm();
        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_doctors_keep_their_workflows_through_the_existing_routes(): void
    {
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $booked = $this->appointment($this->profileA, 'booked');
        $other = $this->appointment($this->profileB, 'booked');
        $slot = $this->slot($this->profileA);

        $this->actingAs($this->doctorA)->post(route('doctor.appointments.confirm', $booked))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->doctorA)->post(route('doctor.slots.block', $slot))->assertRedirect();
        $this->actingAs($this->doctorA)->delete(route('doctor.slots.unblock', $slot))->assertRedirect();
        $this->actingAs($this->doctorA)->get(route('doctor.appointments.index'))->assertOk();
        $this->actingAs($this->doctorA)->get(route('doctor.schedule'))->assertOk();
        $this->actingAs($this->doctorA)->get(route('doctor.patients.index'))->assertOk();
        $this->actingAs($this->doctorA)->get(route('doctor.medical-records.index'))->assertOk();
        $this->actingAs($this->doctorA)->post(route('doctor.availability.store'), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'])->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $booked->fresh()->status);
        $this->assertSame('available', $slot->fresh()->status);
        $this->assertSame(1, $this->profileA->availabilities()->count());
        $this->actingAs($this->doctorA)->post(route('doctor.appointments.confirm', $other))->assertForbidden();
        $this->actingAs($this->doctorA)->post(route('doctor.slots.block', $this->slot($this->profileB)))->assertForbidden();
        $this->assertSame('booked', $other->fresh()->status);
    }

    public function test_the_workspace_resolves_and_authorizes_the_doctor_context(): void
    {
        $workspace = app(DoctorWorkspace::class);
        [$withoutProfile] = [User::factory()->create(['role' => 'doctor'])];

        $this->assertTrue($workspace->forRequest($this->request($this->assistant, $this->profileA), DoctorAccess::VIEW_APPOINTMENTS)->is($this->profileA));
        $this->assertTrue($workspace->forRequest($this->request($this->assistant, $this->profileB), DoctorAccess::MANAGE_SLOTS)->is($this->profileB));
        $this->assertTrue($workspace->forRequest($this->request($this->doctorA), DoctorAccess::START_APPOINTMENTS)->is($this->profileA));
        $this->assertNull($workspace->forRequest($this->request($withoutProfile), DoctorAccess::VIEW_APPOINTMENTS), 'Doctors without a profile keep their existing handling.');

        foreach ([
            'assistant, unassigned doctor' => $this->request($this->assistant, $this->profileC),
            'assistant, denied ability' => [$this->request($this->assistant, $this->profileA), DoctorAccess::START_APPOINTMENTS],
            'assistant without a doctor context' => $this->request($this->assistant),
            'doctor, another doctor in the URL' => $this->request($this->doctorA, $this->profileB),
            'raw id instead of a bound profile' => $this->request($this->assistant, (string) $this->profileA->id),
            'patient' => $this->request($this->patient),
            'admin' => $this->request(User::factory()->create(['role' => 'admin'])),
        ] as $case => $request) {
            [$request, $ability] = is_array($request) ? $request : [$request, DoctorAccess::VIEW_APPOINTMENTS];
            try {
                $workspace->forRequest($request, $ability);
                $this->fail("{$case} must be refused.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $workspace->ensureBelongs($this->profileA, (string) $this->profileA->id);
        $this->expectException(HttpException::class);
        $workspace->ensureBelongs($this->profileA, $this->profileB->id);
    }

    public function test_medical_records_cover_every_assigned_doctor_and_nothing_else(): void
    {
        $inCareOfA = $this->patientWith($this->profileA, 'confirmed');
        $inCareOfB = $this->patientWith($this->profileB, 'completed');
        $onlyUnassigned = $this->patientWith($this->profileC, 'confirmed');
        $cancelledWithA = $this->patientWith($this->profileA, 'cancelled');
        $rejectedWithB = $this->patientWith($this->profileB, 'rejected');
        $documents = collect([$inCareOfA, $inCareOfB, $onlyUnassigned, $cancelledWithA, $rejectedWithB])
            ->mapWithKeys(fn (User $patient) => [$patient->id => $this->document($patient)]);

        $visibleIds = fn (User $actor) => MedicalDocument::query()->visibleToActor($actor)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$documents[$inCareOfA->id]->id, $documents[$inCareOfB->id]->id], $visibleIds($this->assistant));
        $this->assertSame([$documents[$inCareOfA->id]->id], $visibleIds($this->doctorA));
        $this->assertSame([], $visibleIds($this->patient));
        $this->assertSame([], $visibleIds(User::factory()->create(['role' => 'admin'])));
        $this->assertSame([], MedicalDocument::query()->visibleToDoctorProfiles([])->pluck('id')->all());

        DB::enableQueryLog();
        MedicalDocument::query()->visibleToActor($this->assistant)->with(['patient:id,name', 'appointment.slot'])->get();
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()), 'Scoped in the query, not per document.');
        DB::disableQueryLog();

        $this->profileB->assistants()->detach($this->assistant->id);
        $this->assertSame([$documents[$inCareOfA->id]->id], $visibleIds($this->assistant), 'Unassignment removes that doctor immediately.');
    }

    public function test_assistant_document_downloads_are_audited_to_the_assistant_and_upload_or_delete_stay_forbidden(): void
    {
        $inCare = $this->patientWith($this->profileB, 'booked');
        $notInCare = $this->patientWith($this->profileC, 'confirmed');
        $document = $this->document($inCare);
        $foreign = $this->document($notInCare);

        $this->actingAs($this->assistant)->get(route('medical-documents.download', $document))->assertOk();
        $this->actingAs($this->assistant)->get(route('medical-documents.download', $foreign))->assertForbidden();
        $this->actingAs($this->assistant)->delete(route('medical-documents.destroy', $document))->assertForbidden();
        $this->actingAs($this->assistant)->post(route('patient.documents.store'), ['category' => 'other', 'document' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF\n")])->assertForbidden();

        $download = MedicalDocumentEvent::where('action', 'downloaded')->sole();
        $this->assertSame($this->assistant->id, $download->actor_id);
        $this->assertSame($inCare->id, $download->patient_id);
        $this->assertFalse($this->assistant->can('create', MedicalDocument::class));
        $this->assertModelExists($document);
        $this->assertSame(2, MedicalDocument::count());
    }

    public function test_assistants_can_view_but_never_change_assigned_doctors_photos(): void
    {
        $this->profileA->forceFill(['is_accepting_patients' => false])->save();
        Storage::disk(DoctorPhotoStorage::DISK)->put('doctor-photos/a.png', $this->pngBytes());
        $this->profileA->forceFill(['photo_path' => 'doctor-photos/a.png'])->save();
        $unassigned = $this->assistant([]);

        $this->actingAs($this->assistant)->get(route('doctors.photo', $this->profileA))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($unassigned)->get(route('doctors.photo', $this->profileA))->assertNotFound();
        $this->actingAs($this->patient)->get(route('doctors.photo', $this->profileA))->assertNotFound();
        $this->assertFalse($this->assistant->can('update', $this->profileA));
        $this->actingAs($this->assistant)->put(route('doctor.profile.update'), ['professional_title' => 'Changed'])->assertForbidden();

        $this->profileA->assistants()->detach($this->assistant->id);
        $this->actingAs($this->assistant)->get(route('doctors.photo', $this->profileA))->assertNotFound();
        $this->profileA->assistants()->attach($this->assistant->id);
        $this->doctorA->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($this->assistant)->get(route('doctors.photo', $this->profileA))->assertNotFound();
    }

    /**
     * A request as the given user, optionally carrying a {doctorProfile} route parameter like the assistant
     * workspace routes will.
     */
    private function request(User $user, DoctorProfile|string|null $doctorProfile = null): Request
    {
        $request = Request::create('/workspace');
        $route = (new Route('GET', '/workspace/{doctorProfile?}', fn () => null))->bind($request);
        if ($doctorProfile !== null) {
            $route->setParameter(DoctorWorkspace::ROUTE_PARAMETER, $doctorProfile);
        }
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
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

    /**
     * @param  list<DoctorProfile>  $profiles
     */
    private function assistant(array $profiles): User
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        foreach ($profiles as $profile) {
            $profile->assistants()->attach($assistant->id);
        }

        return $assistant->fresh();
    }

    private function slot(DoctorProfile $profile, string $status = 'available'): AppointmentSlot
    {
        $startsAt = now()->startOfHour()->addDays(3)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, string $status, ?User $patient = null): Appointment
    {
        $slot = $this->slot($profile, in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked');

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => ($patient ?? $this->patient)->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function patientWith(DoctorProfile $profile, string $status): User
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointment($profile, $status, $patient);

        return $patient;
    }

    private function document(User $patient): MedicalDocument
    {
        return app(MedicalDocumentService::class)->store($patient, UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF\n"), ['category' => 'other'])->fresh();
    }

    private function pngBytes(): string
    {
        $raw = str_repeat("\0".str_repeat("\x16\x7d\x91", 4), 4);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 4, 4, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }
}
