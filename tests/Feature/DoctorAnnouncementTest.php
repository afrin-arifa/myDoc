<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAnnouncement;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class DoctorAnnouncementTest extends TestCase
{
    use ProvisionsAssistants, RefreshDatabase;

    public function test_only_the_doctors_own_patients_see_an_announcement(): void
    {
        [$doctor, $profile] = $this->doctor();
        [, $otherProfile] = $this->doctor();
        $confirmed = $this->patientWith($profile, 'confirmed');
        $cancelledOnly = $this->patientWith($profile, 'cancelled');
        $otherDoctorsPatient = $this->patientWith($otherProfile, 'confirmed');
        $registered = User::factory()->create(['role' => 'patient']);
        $registered->forceFill(['registered_by_doctor_profile_id' => $profile->id])->save();

        $this->flushSession();

        $this->actingAs($doctor)->get(route('doctor.announcements.index'))->assertOk()->assertSee('Visible to 2 patients');
        $this->post(route('doctor.announcements.store'), ['title' => 'Clinic closed on Friday', 'body' => "We are closed for Eid.\nSee you Saturday."])
            ->assertRedirect(route('doctor.announcements.index'));
        $this->assertDatabaseHas('doctor_workspace_events', ['doctor_profile_id' => $profile->id, 'action' => 'announcement_posted']);

        foreach ([$confirmed, $registered] as $patient) {
            $this->flushSession();
            $this->actingAs($patient)->get(route('patient.dashboard'))->assertSee('Clinic closed on Friday')->assertSee($doctor->name);
        }
        foreach ([$cancelledOnly, $otherDoctorsPatient] as $patient) {
            $this->flushSession();
            $this->actingAs($patient)->get(route('patient.dashboard'))->assertDontSee('Clinic closed on Friday');
        }
    }

    public function test_important_announcements_come_first_and_expired_or_dismissed_ones_disappear(): void
    {
        [$doctor, $profile] = $this->doctor();
        $patient = $this->patientWith($profile, 'completed');

        $this->flushSession();

        $this->actingAs($doctor)->post(route('doctor.announcements.store'), ['title' => 'Regular news', 'body' => 'Hello']);
        $this->post(route('doctor.announcements.store'), ['title' => 'Urgent change', 'body' => 'Hours changed', 'is_important' => '1']);
        $this->post(route('doctor.announcements.store'), ['title' => 'Ends today', 'body' => 'Today only', 'expires_on' => today()->toDateString()]);
        $this->post(route('doctor.announcements.store'), ['title' => 'Past', 'body' => 'x', 'expires_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('expires_on');

        $this->flushSession();

        $this->actingAs($patient)->get(route('patient.dashboard'))->assertSeeInOrder(['Urgent change', 'Ends today', 'Regular news']);

        $this->travelTo(today()->addDay()->setTime(9, 0));
        $this->get(route('patient.dashboard'))->assertDontSee('Ends today')->assertSee('Regular news');

        $regular = DoctorAnnouncement::where('title', 'Regular news')->sole();
        $this->post(route('patient.announcements.dismiss', $regular))->assertRedirect();
        $this->get(route('patient.dashboard'))->assertDontSee('Regular news')->assertSee('Urgent change');

        // Someone else's patient cannot dismiss (or learn about) it.
        [, $otherProfile] = $this->doctor();
        $this->flushSession();
        $this->actingAs($this->patientWith($otherProfile, 'confirmed'))->post(route('patient.announcements.dismiss', DoctorAnnouncement::where('title', 'Urgent change')->sole()))->assertNotFound();
    }

    public function test_patients_find_every_current_notice_of_their_doctors_on_the_notices_page(): void
    {
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor] = $this->doctor();
        $patient = $this->patientWith($profile, 'confirmed');

        $this->actingAs($doctor)->post(route('doctor.announcements.store'), ['title' => 'New lab hours', 'body' => 'Open 8 to 8.']);
        $this->post(route('doctor.announcements.store'), ['title' => 'Holiday', 'body' => 'Closed Friday.']);
        $this->flushSession();
        $this->actingAs($otherDoctor)->post(route('doctor.announcements.store'), ['title' => 'Someone else news', 'body' => 'x']);

        $this->flushSession();
        $this->actingAs($patient)->get(route('patient.dashboard'))->assertSee(route('patient.announcements.index'), false)
            ->assertSee('<span class="site-navbar__count">2<span class="visually-hidden"> new</span></span>', false);
        $this->get(route('patient.announcements.index'))->assertOk()
            ->assertSee('New lab hours')->assertSee('Holiday')->assertDontSee('Someone else news')->assertSee('Mark as read');

        // Read notices leave the dashboard but stay on the notices page.
        $this->post(route('patient.announcements.dismiss', DoctorAnnouncement::where('title', 'Holiday')->sole()), ['from_list' => '1'])->assertRedirect();
        $this->get(route('patient.dashboard'))->assertDontSee('Closed Friday.')->assertSee('New lab hours')
            ->assertSee('<span class="site-navbar__count">1<span class="visually-hidden"> new</span></span>', false);
        $this->get(route('patient.announcements.index'))->assertSee('Closed Friday.');
    }

    public function test_assistants_post_for_their_doctor_and_nobody_else_can_remove_it(): void
    {
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor, $otherProfile] = $this->doctor();
        $assistant = $this->activeAssistant();
        $profile->assistants()->attach($assistant->id, ['assigned_by' => $this->admin()->id]);

        $this->flushSession();

        $this->actingAs($assistant)->get(route('assistant.workspace.announcements.index', $profile))->assertOk()->assertSee('New announcement');
        $this->post(route('assistant.workspace.announcements.store', $profile), ['title' => 'Vaccines in stock', 'body' => 'Book now.'])
            ->assertRedirect(route('assistant.workspace.announcements.index', $profile));
        $announcement = DoctorAnnouncement::sole();
        $this->assertSame([$profile->id, $assistant->id], [$announcement->doctor_profile_id, $announcement->author_id]);
        $this->post(route('assistant.workspace.announcements.store', $otherProfile), ['title' => 'X', 'body' => 'Y'])->assertForbidden();

        $this->flushSession();

        $this->actingAs($otherDoctor)->get(route('doctor.announcements.index'))->assertDontSee('Vaccines in stock');
        $this->delete(route('doctor.announcements.destroy', $announcement))->assertNotFound();

        $this->flushSession();

        $this->actingAs($doctor)->get(route('doctor.announcements.index'))->assertSee('Vaccines in stock')->assertSee($assistant->name.' (assistant)');
        $this->delete(route('doctor.announcements.destroy', $announcement))->assertRedirect(route('doctor.announcements.index'));
        $this->assertSame(0, DoctorAnnouncement::count());
        $this->assertDatabaseHas('doctor_workspace_events', ['doctor_profile_id' => $profile->id, 'action' => 'announcement_removed']);

        $this->flushSession();

        $this->actingAs(User::factory()->create(['role' => 'patient']))->get(route('doctor.announcements.index'))->assertForbidden();
    }

    public function test_the_doctor_and_assistants_are_told_about_each_others_announcements(): void
    {
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor] = $this->doctor();
        $assistant = $this->activeAssistant();
        $profile->assistants()->attach($assistant->id, ['assigned_by' => $this->admin()->id]);
        $counter = '<span class="site-navbar__count">1<span class="visually-hidden"> new</span></span>';

        // The assistant posts: the doctor is told, the assistant is not told about their own post.
        $this->flushSession();
        $this->actingAs($assistant)->post(route('assistant.workspace.announcements.store', $profile), ['title' => 'Flu shots arrived', 'body' => 'Book now.']);
        $this->get(route('assistant.dashboard'))->assertDontSee('New announcements from your team');

        $this->flushSession();
        $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertSee('New announcements from your team')->assertSee('Flu shots arrived')
            ->assertSee($assistant->name.' (assistant)')->assertSee($counter, false);
        $this->get(route('doctor.announcements.index'))->assertSee('patient-notice__new', false);
        $this->get(route('doctor.dashboard'))->assertDontSee('New announcements from your team')->assertDontSee($counter, false);

        // The doctor posts: the assistant is told, on their dashboard and in the workspace menu.
        $this->post(route('doctor.announcements.store'), ['title' => 'Closed on Monday', 'body' => 'Public holiday.']);
        $this->get(route('doctor.dashboard'))->assertDontSee('New announcements from your team');
        $mondayNotice = DoctorAnnouncement::where('title', 'Closed on Monday')->sole();

        $this->flushSession();
        $this->actingAs($assistant)->get(route('assistant.dashboard'))->assertSee('Closed on Monday');
        $this->get(route('assistant.workspace.appointments.index', $profile))->assertSee($counter, false);
        $this->post(route('announcements.read', $mondayNotice))->assertRedirect();
        $this->get(route('assistant.dashboard'))->assertDontSee('Closed on Monday');

        // Nobody outside this doctor's team can mark it, and patients cannot use the staff route.
        $this->flushSession();
        $this->actingAs($otherDoctor)->post(route('announcements.read', $mondayNotice))->assertNotFound();
        $this->get(route('doctor.dashboard'))->assertDontSee('Closed on Monday');
        $this->flushSession();
        $this->actingAs(User::factory()->create(['role' => 'patient']))->post(route('announcements.read', $mondayNotice))->assertForbidden();
    }

    private function patientWith(DoctorProfile $profile, string $status): User
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $start = now()->startOfHour()->addDays(3)->addMinutes(15 * AppointmentSlot::where('doctor_profile_id', $profile->id)->count());
        $slot = AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10), 'status' => $status === 'cancelled' ? 'available' : 'booked']);
        Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical', 'booked_at' => now()]);

        return $patient;
    }
}
