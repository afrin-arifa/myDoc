<?php

namespace Tests\Concerns;

use App\Http\Controllers\AppointmentController;
use App\Models\AppointmentSlot;
use Illuminate\Testing\TestResponse;

/**
 * A patient who opens or submits a time that is no longer open (taken, blocked or started) is sent to the doctor's
 * profile, on that day, with a warning, instead of a 404 or a dead confirm page.
 */
trait AssertsUnavailableSlotRedirect
{
    protected function assertSentToChooseAnotherTime(TestResponse $response, AppointmentSlot $slot): TestResponse
    {
        return $response
            ->assertRedirect(route('doctors.show', ['doctor' => $slot->doctorProfile, 'date' => $slot->starts_at->toDateString()]))
            ->assertSessionHas('warning', AppointmentController::SLOT_UNAVAILABLE_MESSAGE)
            ->assertSessionHasNoErrors();
    }
}
