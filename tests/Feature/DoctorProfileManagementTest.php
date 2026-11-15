<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use App\Services\DoctorPhotoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DoctorProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DoctorPhotoStorage::DISK);
    }

    public function test_doctor_can_complete_their_own_profile_with_qualifications_and_photo(): void
    {
        $doctor = $this->doctor();
        $specialty = Specialty::create(['name' => 'Dermatology', 'is_active' => true]);

        $this->actingAs($doctor)->get(route('doctor.profile'))->assertOk()->assertSee('Complete your profile');

        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, [
            'qualifications' => 'MBBS, MD (Dermatology)',
            'bio' => 'Skin specialist.',
            'specialty_ids' => [$specialty->id],
            'photo' => $this->png('me.png'),
        ]))->assertRedirect();

        $profile = $doctor->doctorProfile->fresh();
        $this->assertSame('MBBS, MD (Dermatology)', $profile->qualifications);
        $this->assertNotNull($profile->photo_path);
        $this->assertStringStartsWith(DoctorPhotoStorage::DIRECTORY.'/', $profile->photo_path);
        $this->assertStringNotContainsString('me.png', $profile->photo_path, 'Uploaded file names are never trusted.');
        Storage::disk(DoctorPhotoStorage::DISK)->assertExists($profile->photo_path);
        $this->assertSame([], $profile->missingProfileSections());
        $this->actingAs($doctor->fresh())->get(route('doctor.profile'))->assertDontSee('Complete your profile');
    }

    public function test_updating_other_fields_preserves_the_photo_and_photo_can_be_replaced_or_removed(): void
    {
        $doctor = $this->doctor();
        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['photo' => $this->png()]));
        $firstPhoto = $doctor->doctorProfile->fresh()->photo_path;

        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['bio' => 'Updated bio']));
        $this->assertSame($firstPhoto, $doctor->doctorProfile->fresh()->photo_path);
        $this->assertSame('Updated bio', $doctor->doctorProfile->fresh()->bio);

        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['photo' => $this->png()]));
        $secondPhoto = $doctor->doctorProfile->fresh()->photo_path;
        $this->assertNotSame($firstPhoto, $secondPhoto);
        Storage::disk(DoctorPhotoStorage::DISK)->assertMissing($firstPhoto);

        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['remove_photo' => 1]));
        $this->assertNull($doctor->doctorProfile->fresh()->photo_path);
        Storage::disk(DoctorPhotoStorage::DISK)->assertMissing($secondPhoto);
    }

    public function test_invalid_photo_uploads_are_rejected(): void
    {
        $doctor = $this->doctor();
        $invalidFiles = [
            'pdf' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'php disguised as png' => UploadedFile::fake()->createWithContent('shell.png', '<?php echo "pwned"; ?>'),
            'html' => UploadedFile::fake()->createWithContent('page.jpg', '<html><script>alert(1)</script></html>'),
            'too large' => UploadedFile::fake()->createWithContent('huge.png', $this->pngBytes(80, 80).str_repeat("\0", 2100 * 1024)),
            'too small' => UploadedFile::fake()->createWithContent('tiny.png', $this->pngBytes(10, 10)),
            'not a file' => 'just a string',
        ];

        foreach ($invalidFiles as $label => $file) {
            $this->actingAs($doctor)
                ->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['photo' => $file]))
                ->assertSessionHasErrors('photo');
            $this->assertNull($doctor->doctorProfile->fresh()->photo_path, "{$label} must not be stored.");
        }

        $this->assertSame([], Storage::disk(DoctorPhotoStorage::DISK)->allFiles());
    }

    public function test_profile_updates_cannot_change_role_status_or_owner(): void
    {
        $doctor = $this->doctor();
        $other = $this->doctor();

        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, [
            'role' => 'admin',
            'deactivated_at' => now()->toDateTimeString(),
            'user_id' => $other->id,
            'doctor_profile_id' => $other->doctorProfile->id,
            'photo_path' => '../../.env',
            'professional_title' => 'Changed title',
        ]))->assertRedirect();

        $this->assertSame('doctor', $doctor->fresh()->role);
        $this->assertNull($doctor->fresh()->deactivated_at);
        $this->assertSame($doctor->id, $doctor->doctorProfile->fresh()->user_id);
        $this->assertNull($doctor->doctorProfile->fresh()->photo_path);
        $this->assertSame('Changed title', $doctor->doctorProfile->fresh()->professional_title);
        $this->assertSame('Physician', $other->doctorProfile->fresh()->professional_title, 'The other doctor is untouched.');
    }

    public function test_doctors_and_patients_cannot_use_admin_profile_management(): void
    {
        $doctor = $this->doctor();
        $other = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);

        foreach ([$doctor, $patient] as $user) {
            $this->actingAs($user)->get(route('admin.doctors.show', $other->doctorProfile))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.doctors.update', $other->doctorProfile), $this->profilePayload($other->doctorProfile, [
                'name' => 'Hijacked', 'email' => $other->email,
            ]))->assertForbidden();
            $this->actingAs($user)->get(route('admin.doctors.index'))->assertForbidden();
        }
        $this->actingAs($patient)->put(route('doctor.profile.update'), $this->profilePayload($other->doctorProfile))->assertForbidden();
        $this->actingAs($patient)->get(route('doctor.profile'))->assertForbidden();

        $this->assertNotSame('Hijacked', $other->fresh()->name);
        $this->assertSame('Physician', $other->doctorProfile->fresh()->professional_title);
        $this->assertFalse($doctor->can('update', $other->doctorProfile));
        $this->assertFalse($patient->can('update', $other->doctorProfile));
    }

    public function test_admin_can_manage_any_doctor_profile_including_photo(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctor();

        $this->actingAs($admin)->patch(route('admin.doctors.update', $doctor->doctorProfile), $this->profilePayload($doctor->doctorProfile, [
            'name' => 'Dr. Updated',
            'email' => $doctor->email,
            'qualifications' => 'MBBS',
            'photo' => $this->png(),
        ]))->assertRedirect();

        $this->assertSame('Dr. Updated', $doctor->fresh()->name);
        $this->assertSame('MBBS', $doctor->doctorProfile->fresh()->qualifications);
        $this->assertNotNull($doctor->doctorProfile->fresh()->photo_path);
        $this->assertSame('doctor', $doctor->fresh()->role);

        $this->actingAs($admin)->patch(route('admin.doctors.update', $doctor->doctorProfile), $this->profilePayload($doctor->doctorProfile, [
            'name' => 'Dr. Updated', 'email' => $doctor->email, 'photo' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
        ]))->assertSessionHasErrors('photo');
    }

    public function test_photos_are_served_through_an_authorizing_route_without_exposing_paths(): void
    {
        $doctor = $this->doctor();
        $this->actingAs($doctor)->put(route('doctor.profile.update'), $this->profilePayload($doctor->doctorProfile, ['photo' => $this->png()]));
        $profile = $doctor->doctorProfile->fresh();
        Auth::logout();
        $this->get(route('doctors.photo', $profile))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'patient']));

        $this->get(route('doctors.show', $profile))->assertOk()->assertSee(route('doctors.photo', $profile))->assertDontSee($profile->photo_path);
        $this->get(route('doctors.index'))->assertSee(route('doctors.photo', $profile));

        $response = $this->get(route('doctors.photo', $profile))->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString(DoctorPhotoStorage::DIRECTORY, (string) $response->headers->get('Content-Disposition'));

        // Once the doctor is hidden from the directory, only the doctor and admins can load the photo.
        $doctor->forceFill(['deactivated_at' => now()])->save();
        $this->get(route('doctors.photo', $profile))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'patient']))->get(route('doctors.photo', $profile))->assertNotFound();
        $this->actingAs($this->doctor())->get(route('doctors.photo', $profile))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('doctors.photo', $profile))->assertOk();

        $this->get(route('doctors.photo', $this->doctor()->doctorProfile))->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(DoctorProfile $profile, array $override = []): array
    {
        return [
            'professional_title' => $profile->professional_title,
            'license_number' => $profile->license_number,
            'consultation_fee' => $profile->consultation_fee,
            'years_of_experience' => $profile->years_of_experience,
            'bio' => $profile->bio,
            'qualifications' => $profile->qualifications,
            'is_accepting_patients' => 1,
            ...$override,
        ];
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes(120, 120));
    }

    /**
     * A real PNG built without GD (not installed for PHP 8.5 here), so image validation sees genuine image data.
     */
    private function pngBytes(int $width, int $height): string
    {
        $raw = '';
        for ($row = 0; $row < $height; $row++) {
            $raw .= "\0".str_repeat("\x16\x7d\x91", $width);
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw))
            .$chunk('IEND', '');
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }
}
