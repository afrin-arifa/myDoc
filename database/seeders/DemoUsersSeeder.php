<?php

namespace Database\Seeders;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    private const PASSWORD = '123456789';

    public function run(): void
    {
        $this->createAccount('Demo Admin', 'admin@gmail.com', 'admin');

        $specialties = collect([
            ['name' => 'General Practice', 'description' => 'Primary and preventive care.'],
            ['name' => 'Internal Medicine', 'description' => 'Adult medical care.'],
        ])->mapWithKeys(
            fn (array $attributes): array => [
                $attributes['name'] => Specialty::firstOrCreate(
                    ['name' => $attributes['name']],
                    $attributes
                ),
            ]
        );

        $doctors = [
            1 => [
                'name' => 'Dr. Maya Bennett',
                'title' => 'Family Medicine Physician',
                'bio' => 'A fictional primary-care profile focused on preventive visits and everyday health needs.',
                'fee' => 65.00,
                'experience' => 8,
            ],
            2 => [
                'name' => 'Dr. Ethan Brooks',
                'title' => 'Internal Medicine Physician',
                'bio' => 'A fictional internal-medicine profile focused on thoughtful adult primary care.',
                'fee' => 80.00,
                'experience' => 11,
            ],
            3 => [
                'name' => 'Dr. Sofia Carter',
                'title' => 'Family Medicine Physician',
                'bio' => 'A fictional family-medicine profile focused on approachable, coordinated care.',
                'fee' => 70.00,
                'experience' => 9,
            ],
            4 => [
                'name' => 'Dr. Noah Ellis',
                'title' => 'Internal Medicine Physician',
                'bio' => 'A fictional internal-medicine profile focused on long-term wellness planning.',
                'fee' => 85.00,
                'experience' => 13,
            ],
            5 => [
                'name' => 'Dr. Lina Foster',
                'title' => 'Family Medicine Physician',
                'bio' => 'A fictional primary-care profile focused on clear guidance and preventive care.',
                'fee' => 75.00,
                'experience' => 7,
            ],
        ];

        for ($number = 1; $number <= 5; $number++) {
            $details = $doctors[$number];
            $doctor = $this->createAccount($details['name'], "doctor{$number}@gmail.com", 'doctor');

            if ($doctor->wasRecentlyCreated || $doctor->name === "Demo Doctor {$number}") {
                $doctor->name = $details['name'];
                $doctor->save();
            }

            $profile = DoctorProfile::firstOrCreate(
                ['user_id' => $doctor->id],
                [
                    'professional_title' => $details['title'],
                    'bio' => $details['bio'],
                    'license_number' => "DEMO-LICENSE-{$number}",
                    'consultation_fee' => $details['fee'],
                    'years_of_experience' => $details['experience'],
                    'is_accepting_patients' => true,
                ]
            );

            if ($profile->professional_title === 'Demo Physician'
                && $profile->bio === 'Demo doctor profile for testing and development.'
                && $profile->license_number === "DEMO-LICENSE-{$number}"
                && (float) $profile->consultation_fee === 50.00
                && (int) $profile->years_of_experience === 5
            ) {
                $profile->update([
                    'professional_title' => $details['title'],
                    'bio' => $details['bio'],
                    'consultation_fee' => $details['fee'],
                    'years_of_experience' => $details['experience'],
                ]);
            }

            $profile->specialties()->syncWithoutDetaching([
                $specialties->get($number % 2 === 0 ? 'Internal Medicine' : 'General Practice')->id,
            ]);
        }

        for ($number = 1; $number <= 10; $number++) {
            $this->createAccount("Demo Patient {$number}", "patient{$number}@gmail.com", 'patient');
        }
    }

    private function createAccount(string $name, string $email, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->role = $role;
            $user->save();
        } elseif ($user->role !== $role) {
            $user->role = $role;
            $user->save();
        }

        return $user;
    }
}
