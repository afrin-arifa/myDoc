@props(['appointment'])
{{--
    The fee stored on the appointment when it was created (never the doctor's current fee). Appointments created before
    fees were stored show "Not recorded".
--}}
<p {{ $attributes }}><strong>{{ App\Services\ConsultationFee::label($appointment->fee_type) ?? 'Consultation fee' }}:</strong>
    <span @class(['text-secondary' => $appointment->consultation_fee === null]) data-appointment-fee>{{ App\Services\ConsultationFee::format($appointment->consultation_fee) }}</span></p>
