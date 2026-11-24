<?php

use App\Models\DoctorProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The readable name shown in a doctor's URLs, e.g. /doctors/maya-bennett. Additive: existing doctors get theirs here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique()->after('user_id');
        });

        $doctors = DB::table('doctor_profiles')
            ->join('users', 'users.id', '=', 'doctor_profiles.user_id')
            ->orderBy('doctor_profiles.id')
            ->get(['doctor_profiles.id', 'users.name']);

        foreach ($doctors as $doctor) {
            DB::table('doctor_profiles')->where('id', $doctor->id)->update(['slug' => DoctorProfile::availableSlug($doctor->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
