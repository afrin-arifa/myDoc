<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PatientPhotoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real 64x64 PNG is built without GD, so image validation sees genuine image data.
     */
    private const SIZE = 64;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(UserPhotoStorage::DISK);
    }

    public function test_a_patient_can_add_replace_keep_and_remove_their_photo(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient)->get(route('patient.profile'))->assertOk()->assertSee('Add photo')->assertDontSee('Remove current photo');

        $this->update($patient, ['photo' => $this->png('me.png')])->assertSessionHasNoErrors();
        $first = $patient->fresh()->photo_path;
        $this->assertStringStartsWith(UserPhotoStorage::DIRECTORY.'/', $first);
        $this->assertStringNotContainsString('me.png', $first);
        Storage::disk(UserPhotoStorage::DISK)->assertExists($first);
        $this->actingAs($patient->fresh())->get(route('patient.profile'))->assertSee('Change photo')->assertSee('Remove current photo')
            ->assertSee(route('users.photo', $patient))->assertDontSee($first);

        $this->update($patient->fresh(), ['address' => 'Dhaka'])->assertSessionHasNoErrors();
        $this->assertSame($first, $patient->fresh()->photo_path);

        $this->update($patient->fresh(), ['photo' => $this->png()])->assertSessionHasNoErrors();
        $second = $patient->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk(UserPhotoStorage::DISK)->assertMissing($first);

        $this->update($patient->fresh(), ['remove_photo' => 1])->assertSessionHasNoErrors();
        $this->assertNull($patient->fresh()->photo_path);
        Storage::disk(UserPhotoStorage::DISK)->assertMissing($second);
    }

    public function test_invalid_files_and_direct_paths_are_rejected(): void
    {
        $patient = $this->patient();

        foreach ([
            'pdf' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'disguised php' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1; ?>'),
            'too large' => UploadedFile::fake()->createWithContent('big.png', $this->pngBytes().str_repeat("\0", 2100 * 1024)),
        ] as $label => $file) {
            $this->update($patient, ['photo' => $file])->assertSessionHasErrors('photo');
            $this->assertNull($patient->fresh()->photo_path, $label);
        }

        $this->update($patient, ['photo_path' => 'secrets/file.png'])->assertSessionHasNoErrors();
        $this->assertNull($patient->fresh()->photo_path);
        $this->assertSame([], Storage::disk(UserPhotoStorage::DISK)->allFiles());
    }

    public function test_only_the_patient_their_care_team_and_admins_can_load_the_photo(): void
    {
        $patient = $this->patient();
        $this->update($patient, ['photo' => $this->png()]);
        $url = route('users.photo', $patient);

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $this->appointment($doctor, $patient);
        $assistant = $this->assistantOf($doctor);
        $otherAssistant = $this->assistantOf($otherDoctor);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([$patient, $doctor, $assistant, $admin] as $allowed) {
            $response = $this->actingAs($allowed->fresh())->get($url)->assertOk();
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        foreach ([$this->patient(), $otherDoctor, $otherAssistant] as $refused) {
            $this->actingAs($refused->fresh())->get($url)->assertNotFound();
        }

        // Access ends with the assignment.
        $doctor->doctorProfile->assistants()->detach($assistant->id);
        $this->actingAs($assistant->fresh())->get($url)->assertNotFound();

        // Accounts without a photo, and accounts that are not patients, have nothing to load.
        $this->actingAs($admin)->get(route('users.photo', $this->patient()))->assertNotFound();
        $this->get(route('users.photo', $doctor))->assertNotFound();
    }

    public function test_the_photo_is_shown_to_the_doctor_in_their_patient_list_and_appointments(): void
    {
        $patient = $this->patient();
        $this->update($patient, ['photo' => $this->png()]);
        $doctor = $this->doctor();
        $appointment = $this->appointment($doctor, $patient);
        $this->actingAs($doctor->fresh());

        foreach ([route('doctor.patients.index'), route('doctor.appointments.index'), route('doctor.appointments.show', $appointment), route('doctor.dashboard')] as $page) {
            $this->get($page)->assertOk()->assertSee(route('users.photo', $patient));
        }
    }

    private function update(User $patient, array $override): TestResponse
    {
        return $this->actingAs($patient)->put(route('patient.profile.update'), ['name' => $patient->name, 'email' => $patient->email, ...$override]);
    }

    private function patient(): User
    {
        return User::factory()->create(['role' => 'patient']);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50]);

        return $doctor->load('doctorProfile');
    }

    private function assistantOf(User $doctor): User
    {
        $assistant = User::factory()->create(['role' => User::ROLE_ASSISTANT]);
        $doctor->doctorProfile->assistants()->attach($assistant->id, ['assigned_by' => User::factory()->create(['role' => 'admin'])->id]);

        return $assistant;
    }

    private function appointment(User $doctor, User $patient): Appointment
    {
        $start = now()->addDay()->setTime(10, 0);
        $slot = AppointmentSlot::create(['doctor_profile_id' => $doctor->doctorProfile->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10), 'status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => 'booked', 'consultation_type' => 'physical', 'booked_at' => now()]);
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes());
    }

    private function pngBytes(): string
    {
        $raw = str_repeat("\0".str_repeat("\x16\x7d\x91", self::SIZE), self::SIZE);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', self::SIZE, self::SIZE, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw))
            .$chunk('IEND', '');
    }
}
