<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_users_seeder_creates_expected_accounts_and_doctor_profiles(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(16, User::whereIn('email', $this->demoEmails())->count());
        $this->assertSame(1, User::where('role', 'admin')->where('email', 'admin@gmail.com')->count());
        $this->assertSame(5, User::where('role', 'doctor')->whereIn('email', $this->doctorEmails())->count());
        $this->assertSame(10, User::where('role', 'patient')->whereIn('email', $this->patientEmails())->count());
        $this->assertSame(5, DoctorProfile::whereHas('user', fn ($users) => $users->whereIn('email', $this->doctorEmails()))->count());
        $this->assertSame(
            ['Dr. Maya Bennett', 'Dr. Ethan Brooks', 'Dr. Sofia Carter', 'Dr. Noah Ellis', 'Dr. Lina Foster'],
            User::whereIn('email', $this->doctorEmails())->orderBy('email')->pluck('name')->all()
        );
        $this->assertSame(
            5,
            DoctorProfile::whereHas('user', fn ($users) => $users->whereIn('email', $this->doctorEmails()))
                ->where('bio', 'not like', 'Demo doctor profile%')
                ->count()
        );

        $admin = User::where('email', 'admin@gmail.com')->firstOrFail();
        $this->assertTrue(Hash::check('123456789', $admin->password));

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs(User::where('email', 'doctor1@gmail.com')->firstOrFail())
            ->get(route('doctor.dashboard'))->assertOk();
        $this->actingAs(User::where('email', 'patient1@gmail.com')->firstOrFail())
            ->get(route('patient.dashboard'))->assertOk();

        $this->get(route('doctors.index'))
            ->assertOk()
            ->assertSee('Dr. Maya Bennett')
            ->assertSee('Dr. Lina Foster');
        $this->get(route('doctors.show', DoctorProfile::whereHas(
            'user',
            fn ($users) => $users->where('email', 'doctor1@gmail.com')
        )->firstOrFail()))->assertOk()->assertSee('Family Medicine Physician');
        $this->actingAs(User::where('email', 'doctor1@gmail.com')->firstOrFail())
            ->get(route('doctor.appointments.index'))->assertOk();
    }

    public function test_demo_users_seeder_is_idempotent_and_preserves_existing_account_data(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $patient = User::where('email', 'patient1@gmail.com')->firstOrFail();
        $patient->update(['name' => 'Custom patient name']);

        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(16, User::whereIn('email', $this->demoEmails())->count());
        $this->assertSame('Custom patient name', $patient->fresh()->name);
    }

    public function test_demo_accounts_can_log_in_to_their_role_dashboards(): void
    {
        $this->seed(DemoUsersSeeder::class);

        foreach ([
            ['admin@gmail.com', 'admin.dashboard'],
            ['doctor1@gmail.com', 'doctor.dashboard'],
            ['patient1@gmail.com', 'patient.dashboard'],
        ] as [$email, $dashboard]) {
            $this->post(route('login'), [
                'email' => $email,
                'password' => '123456789',
            ])->assertRedirect(route($dashboard));

            $this->post(route('logout'));
        }
    }

    /**
     * @return list<string>
     */
    private function demoEmails(): array
    {
        return array_merge(['admin@gmail.com'], $this->doctorEmails(), $this->patientEmails());
    }

    /**
     * @return list<string>
     */
    private function doctorEmails(): array
    {
        return array_map(fn (int $number): string => "doctor{$number}@gmail.com", range(1, 5));
    }

    /**
     * @return list<string>
     */
    private function patientEmails(): array
    {
        return array_map(fn (int $number): string => "patient{$number}@gmail.com", range(1, 10));
    }
}
