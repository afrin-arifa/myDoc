<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 data backfill (approved): record the fee of existing appointments only where it is provable, i.e. the
 * doctor's profile has not been edited since the appointment was booked, so its current fee is the fee at booking.
 * Any profile edit updates doctor_profiles.updated_at, so appointments that cannot be proven stay NULL and are shown
 * as "Not recorded" instead of guessing. Follow-ups are skipped (none existed before follow-up fees).
 *
 * Only consultation_fee and fee_type are written; updated_at is kept as it was. down() clears the rows that match the
 * same rule and still hold the doctor's current fee as a "standard" snapshot, again keeping updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->provableAppointments()->get(['appointments.id', 'appointments.updated_at', 'doctor_profiles.consultation_fee']) as $row) {
                DB::table('appointments')->where('id', $row->id)->whereNull('consultation_fee')->update([
                    'consultation_fee' => $row->consultation_fee,
                    'fee_type' => 'standard',
                    'updated_at' => $row->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $ids = $this->provableAppointments(includeFilled: true)
                ->where('appointments.fee_type', 'standard')
                ->whereColumn('appointments.consultation_fee', 'doctor_profiles.consultation_fee')
                ->get(['appointments.id', 'appointments.updated_at']);

            foreach ($ids as $row) {
                DB::table('appointments')->where('id', $row->id)->update([
                    'consultation_fee' => null,
                    'fee_type' => null,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });
    }

    private function provableAppointments(bool $includeFilled = false): Builder
    {
        return DB::table('appointments')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'appointments.appointment_slot_id')
            ->join('doctor_profiles', 'doctor_profiles.id', '=', 'appointment_slots.doctor_profile_id')
            ->when(! $includeFilled, fn ($query) => $query->whereNull('appointments.consultation_fee'))
            ->whereNull('appointments.follow_up_of_id')
            ->whereNotNull('doctor_profiles.updated_at')
            ->whereRaw('doctor_profiles.updated_at <= COALESCE(appointments.booked_at, appointments.created_at)')
            ->orderBy('appointments.id');
    }
};
