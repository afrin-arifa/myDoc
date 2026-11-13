<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use App\Rules\BangladeshiMobileNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsPreferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function phoneNumbers(): array
    {
        return [
            'local' => ['01712345678', '8801712345678'],
            'local with dashes' => ['017-1234-5678', '8801712345678'],
            'international with plus' => ['+8801912345678', '8801912345678'],
            'international without plus' => ['8801312345678', '8801312345678'],
            'international with spaces' => ['+880 1612 345678', '8801612345678'],
            'international dialling prefix' => ['008801512345678', '8801512345678'],
            'invalid operator prefix' => ['01212345678', null],
            'too short' => ['0171234567', null],
            'too long' => ['017123456789', null],
            'landline' => ['029876543', null],
            'foreign' => ['+911234567890', null],
            'text' => ['call me', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function test_bangladeshi_mobile_numbers_are_normalized(string $input, ?string $expected): void
    {
        $this->assertSame($expected, BangladeshiMobileNumber::normalize($input));
    }

    public function test_existing_and_new_users_are_opted_out_by_default(): void
    {
        $user = User::factory()->create(['role' => 'patient']);

        $this->assertFalse($user->fresh()->sms_notifications_enabled);
        $this->assertFalse($user->fresh()->wantsAppointmentSms());
    }

    public function test_patient_can_opt_in_with_a_local_number_that_is_stored_normalized(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)->get(route('patient.profile'))->assertOk()
            ->assertSee('name="sms_notifications_enabled"', false)
            ->assertSee('Email notifications continue either way.');

        $this->actingAs($patient)->put(route('patient.profile.update'), $this->profile(['phone' => '017-1234-5678', 'sms_notifications_enabled' => '1']))
            ->assertSessionHasNoErrors();

        $patient->refresh();
        $this->assertSame('8801712345678', $patient->phone);
        $this->assertTrue($patient->sms_notifications_enabled);
        $this->assertTrue($patient->wantsAppointmentSms());
        $this->actingAs($patient)->get(route('patient.profile'))->assertSee('checked', false);

        $this->actingAs($patient)->put(route('patient.profile.update'), $this->profile(['phone' => '01712345678', 'sms_notifications_enabled' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($patient->fresh()->sms_notifications_enabled);
    }

    public function test_opting_in_requires_a_valid_mobile_number(): void
    {
        $patient = User::factory()->create(['role' => 'patient', 'phone' => null]);

        $this->actingAs($patient)->put(route('patient.profile.update'), $this->profile(['phone' => '', 'sms_notifications_enabled' => '1']))
            ->assertSessionHasErrors(['phone' => 'Add your mobile number to receive appointment SMS.']);
        $this->actingAs($patient)->put(route('patient.profile.update'), $this->profile(['phone' => '01212345678', 'sms_notifications_enabled' => '1']))
            ->assertSessionHasErrors('phone');

        $this->assertFalse($patient->fresh()->sms_notifications_enabled);
        $this->assertNull($patient->fresh()->phone);
    }

    public function test_omitting_the_preference_leaves_it_unchanged(): void
    {
        $patient = User::factory()->create(['role' => 'patient', 'phone' => '8801712345678', 'sms_notifications_enabled' => true]);

        $this->actingAs($patient)->put(route('patient.profile.update'), [
            'name' => 'Renamed Patient',
            'email' => $patient->email,
            'phone' => '01712345678',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($patient->fresh()->sms_notifications_enabled);
        $this->assertSame('Renamed Patient', $patient->fresh()->name);
    }

    public function test_doctor_can_opt_in_from_account_settings(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        $this->actingAs($doctor)->get(route('doctor.settings'))->assertOk()->assertSee('name="sms_notifications_enabled"', false);
        $this->actingAs($doctor)->put(route('doctor.account.update'), [
            'name' => $doctor->name,
            'email' => $doctor->email,
            'phone' => '+880 1812 345678',
            'sms_notifications_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('8801812345678', $doctor->fresh()->phone);
        $this->assertTrue($doctor->fresh()->sms_notifications_enabled);
    }

    public function test_admin_forms_normalize_phones_reject_invalid_ones_and_never_opt_users_in(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $payload = ['name' => $patient->name, 'email' => $patient->email, 'role' => 'patient', 'sms_notifications_enabled' => '1'];

        $this->actingAs($admin)->patch(route('admin.users.update', $patient), [...$payload, 'phone' => 'not a phone'])->assertSessionHasErrors('phone');
        $this->actingAs($admin)->patch(route('admin.users.update', $patient), [...$payload, 'phone' => '01712345678'])->assertSessionHasNoErrors();

        $this->assertSame('8801712345678', $patient->fresh()->phone);
        $this->assertFalse($patient->fresh()->sms_notifications_enabled, 'Only the user can opt in to SMS.');
    }

    public function test_legacy_free_text_phone_numbers_are_preserved_but_never_texted(): void
    {
        $patient = User::factory()->create(['role' => 'patient', 'phone' => '0170-SHARED', 'sms_notifications_enabled' => true]);

        $this->assertSame('0170-SHARED', $patient->fresh()->phone, 'Existing data is not rewritten.');
        $this->assertNull($patient->smsPhoneNumber());
        $this->actingAs($patient)->get(route('patient.profile'))->assertOk()->assertSee('0170-SHARED');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function profile(array $overrides): array
    {
        return ['name' => 'Patient Name', 'email' => 'patient-'.uniqid().'@example.com', 'address' => '', ...$overrides];
    }
}
