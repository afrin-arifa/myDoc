<?php

namespace App\Services;

use App\Models\DoctorProfile;

/**
 * Which consultation fee applies to a new appointment, and how fees are written.
 *
 * An appointment stores the fee that applied when it was created (appointments.consultation_fee and fee_type) and is
 * never recalculated: later fee changes, rescheduling, cancellation or completion leave it as it was. A follow-up uses
 * the doctor's follow-up fee when one is set, otherwise the regular fee. Payment itself is not part of Release 1.
 */
class ConsultationFee
{
    public const TYPE_STANDARD = 'standard';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const CURRENCY = 'BDT';

    /**
     * Largest fee the decimal(10,2) columns hold.
     */
    public const MAX = 99999999.99;

    /**
     * The fee for a new appointment with this doctor, read from the database at that moment (not from a profile that
     * may have been loaded earlier in the request).
     *
     * @return array{fee: string, type: string}
     */
    public static function forNewAppointment(DoctorProfile|int $doctorProfile, bool $isFollowUp): array
    {
        $fees = DoctorProfile::query()
            ->whereKey($doctorProfile instanceof DoctorProfile ? $doctorProfile->id : $doctorProfile)
            ->firstOrFail(['consultation_fee', 'follow_up_fee']);

        if ($isFollowUp && $fees->follow_up_fee !== null) {
            return ['fee' => (string) $fees->follow_up_fee, 'type' => self::TYPE_FOLLOW_UP];
        }

        return ['fee' => (string) $fees->consultation_fee, 'type' => $isFollowUp ? self::TYPE_FOLLOW_UP : self::TYPE_STANDARD];
    }

    /**
     * "BDT 1,500.00", or the fallback when no fee was recorded (appointments created before fees were stored).
     */
    public static function format(string|int|float|null $amount, string $missing = 'Not recorded'): string
    {
        return $amount === null || $amount === '' ? $missing : self::CURRENCY.' '.number_format((float) $amount, 2);
    }

    public static function label(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_FOLLOW_UP => 'Follow-up fee',
            self::TYPE_STANDARD => 'Consultation fee',
            default => null,
        };
    }
}
