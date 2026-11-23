<?php

use App\Http\Controllers\Admin\AdminAppointmentController;
use App\Http\Controllers\Admin\AdminAssistantController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDoctorController;
use App\Http\Controllers\Admin\AdminMedicalDocumentController;
use App\Http\Controllers\Admin\AdminSmsIssueController;
use App\Http\Controllers\Admin\AdminSpecialtyController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWaitlistController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Assistant\AssistantAccountController;
use App\Http\Controllers\Assistant\AssistantDashboardController;
use App\Http\Controllers\Assistant\AssistantInvitationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Doctor\DoctorAppointmentController;
use App\Http\Controllers\Doctor\DoctorAvailabilityController;
use App\Http\Controllers\Doctor\DoctorInvitationController;
use App\Http\Controllers\Doctor\DoctorLocationController;
use App\Http\Controllers\Doctor\DoctorMedicalRecordController;
use App\Http\Controllers\Doctor\DoctorPatientController;
use App\Http\Controllers\Doctor\DoctorProfileController;
use App\Http\Controllers\Doctor\DoctorScheduleController;
use App\Http\Controllers\Doctor\DoctorSlotController;
use App\Http\Controllers\Doctor\DoctorWaitlistController;
use App\Http\Controllers\Doctor\GoogleCalendarController;
use App\Http\Controllers\DoctorDirectoryController;
use App\Http\Controllers\DoctorPhotoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MedicalDocumentController;
use App\Http\Controllers\PatientWaitlistController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// myDoc has no guest access: apart from the sign-in, registration, password recovery and invitation routes below,
// every page requires an authenticated session. The root URL only sends signed-in users to their dashboard.
Route::get('/', [HomeController::class, 'index'])->middleware('auth')->name('home');

// The doctor directory and profiles (with their bookable slots) are for active patients and admins only.
Route::middleware(['auth', 'role:patient,admin'])->group(function () {
    Route::get('/doctors', [DoctorDirectoryController::class, 'index'])->name('doctors.index');
    Route::get('/doctors/{doctor}', [DoctorDirectoryController::class, 'show'])->name('doctors.show');
});

// Photos are shown inside every role's pages; DoctorProfilePolicy::viewPhoto decides per photo.
Route::get('/doctors/{doctor}/photo', [DoctorPhotoController::class, 'show'])->middleware('auth')->name('doctors.photo');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.update');

    Route::get('/doctor/invitation/{token}', [DoctorInvitationController::class, 'show'])
        ->middleware('throttle:doctor-invitations')
        ->name('doctor.invitation.show');
    Route::post('/doctor/invitation', [DoctorInvitationController::class, 'store'])
        ->middleware('throttle:doctor-invitations')
        ->name('doctor.invitation.accept');
    Route::get('/doctor/invitation-accepted', [DoctorInvitationController::class, 'activated'])
        ->name('doctor.invitation.activated');

    // Doctor Assistant invitations (password setup; the assistant then signs in normally).
    Route::get('/assistant/invitation/{token}', [AssistantInvitationController::class, 'show'])
        ->middleware('throttle:assistant-invitations')
        ->name('assistant.invitation.show');
    Route::post('/assistant/invitation', [AssistantInvitationController::class, 'store'])
        ->middleware('throttle:assistant-invitations')
        ->name('assistant.invitation.accept');
    Route::get('/assistant/invitation-accepted', [AssistantInvitationController::class, 'activated'])
        ->name('assistant.invitation.activated');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->put('/profile/password', [ProfileController::class, 'updatePassword'])
    ->name('profile.password.update');

// Medical documents: shared download, preview and delete routes, authorized per document by MedicalDocumentPolicy
// (patients: own documents; doctors: patients in their care; admins: all). Assistants are refused here: they use
// the download and preview routes inside a doctor's workspace below, which carry the doctor context.
Route::middleware('auth')->group(function () {
    Route::get('/medical-documents/{document}/download', [MedicalDocumentController::class, 'download'])
        ->middleware('throttle:60,1')
        ->name('medical-documents.download');
    Route::get('/medical-documents/{document}/preview', [MedicalDocumentController::class, 'preview'])
        ->middleware('throttle:120,1')
        ->name('medical-documents.preview');
    Route::delete('/medical-documents/{document}', [MedicalDocumentController::class, 'destroy'])
        ->name('medical-documents.destroy');
});

Route::middleware(['auth', 'role:patient'])->group(function () {
    Route::get('/patient/dashboard', [AppointmentController::class, 'index'])->name('patient.dashboard');
    Route::get('/patient/profile', [ProfileController::class, 'patient'])->name('patient.profile');
    Route::put('/patient/profile', [ProfileController::class, 'updatePatient'])->name('patient.profile.update');
    Route::delete('/patient/account', [ProfileController::class, 'deactivate'])->name('patient.account.deactivate');
    Route::get('/patient/appointments', [AppointmentController::class, 'index'])->name('patient.appointments.index');
    Route::get('/patient/documents', [MedicalDocumentController::class, 'index'])->name('patient.documents.index');
    Route::post('/patient/documents', [MedicalDocumentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('patient.documents.store');
    Route::get('/patient/appointments/{appointment}', [AppointmentController::class, 'show'])
        ->name('patient.appointments.show');
    Route::get('/appointment-slots/{slot}/confirm', [AppointmentController::class, 'confirm'])
        ->name('appointment-slots.confirm');
    Route::post('/appointment-slots/{slot}/book', [AppointmentController::class, 'store'])
        ->name('appointment-slots.book');
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
        ->name('appointments.cancel');
    Route::post('/doctors/{doctor}/waitlist', [PatientWaitlistController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('patient.waitlist.store');
    Route::get('/patient/waitlist', [PatientWaitlistController::class, 'index'])->name('patient.waitlist.index');
    Route::delete('/patient/waitlist/{entry}', [PatientWaitlistController::class, 'destroy'])->name('patient.waitlist.destroy');
    Route::patch('/patient/waitlist/{entry}/resume', [PatientWaitlistController::class, 'resume'])->name('patient.waitlist.resume');
    Route::get('/patient/waitlist/offers/{offer}', [PatientWaitlistController::class, 'showOffer'])->name('patient.waitlist.offers.show');
    Route::post('/patient/waitlist/offers/{offer}/accept', [PatientWaitlistController::class, 'accept'])->name('patient.waitlist.offers.accept');
    Route::post('/patient/waitlist/offers/{offer}/decline', [PatientWaitlistController::class, 'decline'])->name('patient.waitlist.offers.decline');
});

Route::middleware(['auth', 'role:doctor'])->group(function () {
    Route::get('/doctor/dashboard', [DoctorProfileController::class, 'show'])->name('doctor.dashboard');
    Route::get('/doctor/schedule', [DoctorScheduleController::class, 'index'])->name('doctor.schedule');
    Route::get('/doctor/profile', [DoctorProfileController::class, 'profile'])->name('doctor.profile');
    Route::get('/doctor/settings', [DoctorProfileController::class, 'settings'])->name('doctor.settings');
    Route::put('/doctor/profile', [DoctorProfileController::class, 'update'])->name('doctor.profile.update');
    Route::put('/doctor/account', [DoctorProfileController::class, 'updateAccount'])->name('doctor.account.update');
    Route::get('/doctor/google-calendar/connect', [GoogleCalendarController::class, 'connect'])
        ->name('doctor.google-calendar.connect');
    Route::get('/doctor/google-calendar/callback', [GoogleCalendarController::class, 'callback'])
        ->name('doctor.google-calendar.callback');
    Route::delete('/doctor/google-calendar/disconnect', [GoogleCalendarController::class, 'disconnect'])
        ->name('doctor.google-calendar.disconnect');
    Route::post('/doctor/availability', [DoctorAvailabilityController::class, 'store'])->name('doctor.availability.store');
    Route::patch('/doctor/availability/{availability}', [DoctorAvailabilityController::class, 'update'])
        ->name('doctor.availability.update');
    Route::delete('/doctor/availability/{availability}', [DoctorAvailabilityController::class, 'destroy'])
        ->name('doctor.availability.destroy');
    Route::post('/doctor/locations', [DoctorLocationController::class, 'store'])->name('doctor.locations.store');
    Route::patch('/doctor/locations/{location}', [DoctorLocationController::class, 'update'])->name('doctor.locations.update');
    Route::patch('/doctor/locations/{location}/status', [DoctorLocationController::class, 'updateStatus'])->name('doctor.locations.status');
    Route::delete('/doctor/locations/{location}', [DoctorLocationController::class, 'destroy'])->name('doctor.locations.destroy');
    Route::patch('/doctor/waitlist/settings', [DoctorWaitlistController::class, 'updateSettings'])->name('doctor.waitlist.settings');
    Route::post('/doctor/waitlist/entries/{entry}/offer', [DoctorWaitlistController::class, 'offer'])->name('doctor.waitlist.offer');
    Route::post('/doctor/waitlist/offers/{offer}/withdraw', [DoctorWaitlistController::class, 'withdraw'])->name('doctor.waitlist.withdraw');
    Route::post('/doctor/slots/preview', [DoctorSlotController::class, 'preview'])->name('doctor.slots.preview');
    Route::post('/doctor/slots/generate', [DoctorSlotController::class, 'store'])->name('doctor.slots.generate');
    Route::post('/doctor/slots/{slot}/block', [DoctorSlotController::class, 'block'])->name('doctor.slots.block');
    Route::delete('/doctor/slots/{slot}/block', [DoctorSlotController::class, 'unblock'])->name('doctor.slots.unblock');
    Route::get('/doctor/appointments', [DoctorAppointmentController::class, 'index'])
        ->name('doctor.appointments.index');
    Route::get('/doctor/appointments/create', [DoctorAppointmentController::class, 'create'])
        ->name('doctor.appointments.create');
    Route::post('/doctor/appointments', [DoctorAppointmentController::class, 'store'])
        ->name('doctor.appointments.store');
    Route::get('/doctor/patients', [DoctorPatientController::class, 'index'])->name('doctor.patients.index');
    Route::get('/doctor/medical-records', [DoctorMedicalRecordController::class, 'index'])->name('doctor.medical-records.index');
    Route::get('/doctor/patients/search', [DoctorAppointmentController::class, 'searchPatients'])
        ->middleware('throttle:60,1')
        ->name('doctor.patients.search');
    Route::get('/doctor/appointments/{appointment}', [DoctorAppointmentController::class, 'show'])
        ->name('doctor.appointments.show');
    Route::post('/doctor/appointments/{appointment}/confirm', [DoctorAppointmentController::class, 'confirm'])
        ->name('doctor.appointments.confirm');
    Route::post('/doctor/appointments/{appointment}/reject', [DoctorAppointmentController::class, 'reject'])
        ->name('doctor.appointments.reject');
    Route::post('/doctor/appointments/{appointment}/cancel', [DoctorAppointmentController::class, 'cancel'])
        ->name('doctor.appointments.cancel');
    Route::post('/doctor/appointments/{appointment}/start', [DoctorAppointmentController::class, 'start'])
        ->name('doctor.appointments.start');
    Route::post('/doctor/appointments/{appointment}/complete', [DoctorAppointmentController::class, 'complete'])
        ->name('doctor.appointments.complete');
    Route::get('/doctor/appointments/{appointment}/reschedule', [DoctorAppointmentController::class, 'rescheduleForm'])
        ->name('doctor.appointments.reschedule');
    Route::post('/doctor/appointments/{appointment}/reschedule', [DoctorAppointmentController::class, 'reschedule'])
        ->name('doctor.appointments.reschedule.store');
});

// Doctor Assistant workspace. Every {doctorProfile} route is guarded by works-for-doctor (current assignment and
// both accounts active, checked on every request) and then by the specific ability in each controller. The doctor
// controllers are reused: DoctorWorkspace resolves the doctor from {doctorProfile}; the actor stays the assistant.
// There are deliberately no start or complete routes for assistants.
Route::middleware(['auth', 'role:assistant'])->prefix('assistant')->name('assistant.')->group(function () {
    Route::get('/dashboard', [AssistantDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/doctors', [AssistantDashboardController::class, 'doctors'])->name('doctors.index');
    Route::get('/account', [AssistantAccountController::class, 'show'])->name('account');

    Route::prefix('/doctors/{doctorProfile}')->name('workspace.')->middleware('works-for-doctor')->group(function () {
        Route::get('/', [AssistantDashboardController::class, 'workspace'])->name('show');
        Route::get('/appointments', [DoctorAppointmentController::class, 'index'])->name('appointments.index');
        Route::get('/appointments/create', [DoctorAppointmentController::class, 'create'])->name('appointments.create');
        Route::post('/appointments', [DoctorAppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments/{appointment}', [DoctorAppointmentController::class, 'show'])->name('appointments.show');
        Route::post('/appointments/{appointment}/confirm', [DoctorAppointmentController::class, 'confirm'])->name('appointments.confirm');
        Route::post('/appointments/{appointment}/reject', [DoctorAppointmentController::class, 'reject'])->name('appointments.reject');
        Route::post('/appointments/{appointment}/cancel', [DoctorAppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::get('/appointments/{appointment}/reschedule', [DoctorAppointmentController::class, 'rescheduleForm'])->name('appointments.reschedule');
        Route::post('/appointments/{appointment}/reschedule', [DoctorAppointmentController::class, 'reschedule'])->name('appointments.reschedule.store');
        Route::get('/patients', [DoctorPatientController::class, 'index'])->name('patients.index');
        Route::get('/patients/search', [DoctorAppointmentController::class, 'searchPatients'])
            ->middleware('throttle:60,1')
            ->name('patients.search');
        Route::get('/schedule', [DoctorScheduleController::class, 'index'])->name('schedule');
        Route::post('/availability', [DoctorAvailabilityController::class, 'store'])->name('availability.store');
        Route::patch('/availability/{availability}', [DoctorAvailabilityController::class, 'update'])->name('availability.update');
        Route::delete('/availability/{availability}', [DoctorAvailabilityController::class, 'destroy'])->name('availability.destroy');
        Route::post('/locations', [DoctorLocationController::class, 'store'])->name('locations.store');
        Route::patch('/locations/{location}', [DoctorLocationController::class, 'update'])->name('locations.update');
        Route::patch('/locations/{location}/status', [DoctorLocationController::class, 'updateStatus'])->name('locations.status');
        Route::delete('/locations/{location}', [DoctorLocationController::class, 'destroy'])->name('locations.destroy');
        Route::patch('/waitlist/settings', [DoctorWaitlistController::class, 'updateSettings'])->name('waitlist.settings');
        Route::post('/waitlist/entries/{entry}/offer', [DoctorWaitlistController::class, 'offer'])->name('waitlist.offer');
        Route::post('/waitlist/offers/{offer}/withdraw', [DoctorWaitlistController::class, 'withdraw'])->name('waitlist.withdraw');
        Route::post('/slots/preview', [DoctorSlotController::class, 'preview'])->name('slots.preview');
        Route::post('/slots/generate', [DoctorSlotController::class, 'store'])->name('slots.generate');
        Route::post('/slots/{slot}/block', [DoctorSlotController::class, 'block'])->name('slots.block');
        Route::delete('/slots/{slot}/block', [DoctorSlotController::class, 'unblock'])->name('slots.unblock');
        Route::get('/medical-records', [DoctorMedicalRecordController::class, 'index'])->name('medical-records.index');
        Route::get('/medical-documents/{document}/download', [MedicalDocumentController::class, 'download'])
            ->middleware('throttle:60,1')
            ->name('medical-documents.download');
        Route::get('/medical-documents/{document}/preview', [MedicalDocumentController::class, 'preview'])
            ->middleware('throttle:120,1')
            ->name('medical-documents.preview');
    });
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
    Route::get('/admin/doctors', [AdminDoctorController::class, 'index'])->name('admin.doctors.index');
    Route::get('/admin/doctors/create', [AdminDoctorController::class, 'create'])->name('admin.doctors.create');
    Route::post('/admin/doctors', [AdminDoctorController::class, 'store'])->name('admin.doctors.store');
    Route::patch('/admin/doctors/{doctor}/status', [AdminDoctorController::class, 'updateStatus'])->name('admin.doctors.status');
    Route::post('/admin/doctors/{doctor}/invitation', [AdminDoctorController::class, 'resendInvitation'])
        ->middleware('throttle:10,1')
        ->name('admin.doctors.invitation');
    Route::get('/admin/doctors/{doctor}', [AdminDoctorController::class, 'show'])->name('admin.doctors.show');
    Route::patch('/admin/doctors/{doctor}', [AdminDoctorController::class, 'update'])->name('admin.doctors.update');
    Route::get('/admin/specialties', [AdminSpecialtyController::class, 'index'])->name('admin.specialties.index');
    Route::post('/admin/specialties', [AdminSpecialtyController::class, 'store'])->name('admin.specialties.store');
    Route::patch('/admin/specialties/{specialty}', [AdminSpecialtyController::class, 'update'])->name('admin.specialties.update');
    Route::delete('/admin/specialties/{specialty}', [AdminSpecialtyController::class, 'destroy'])->name('admin.specialties.destroy');
    Route::get('/admin/appointments', [AdminAppointmentController::class, 'index'])->name('admin.appointments.index');
    Route::get('/admin/medical-documents', [AdminMedicalDocumentController::class, 'index'])->name('admin.medical-documents.index');
    Route::get('/admin/medical-documents/{document}', [AdminMedicalDocumentController::class, 'show'])->name('admin.medical-documents.show');
    Route::get('/admin/appointments/{appointment}', [AdminAppointmentController::class, 'show'])->name('admin.appointments.show');
    Route::get('/admin/sms-issues', [AdminSmsIssueController::class, 'index'])->name('admin.sms-issues.index');
    Route::get('/admin/waitlist', [AdminWaitlistController::class, 'index'])->name('admin.waitlist.index');
    Route::post('/admin/sms-issues/{notification}/retry', [AdminSmsIssueController::class, 'retry'])
        ->middleware('throttle:30,1')
        ->name('admin.sms-issues.retry');
    Route::get('/admin/assistants', [AdminAssistantController::class, 'index'])->name('admin.assistants.index');
    Route::get('/admin/assistants/create', [AdminAssistantController::class, 'create'])->name('admin.assistants.create');
    Route::post('/admin/assistants', [AdminAssistantController::class, 'store'])->name('admin.assistants.store');
    Route::get('/admin/assistants/{assistant}', [AdminAssistantController::class, 'show'])->name('admin.assistants.show');
    Route::get('/admin/assistants/{assistant}/edit', [AdminAssistantController::class, 'edit'])->name('admin.assistants.edit');
    Route::patch('/admin/assistants/{assistant}', [AdminAssistantController::class, 'update'])->name('admin.assistants.update');
    Route::patch('/admin/assistants/{assistant}/status', [AdminAssistantController::class, 'updateStatus'])->name('admin.assistants.status');
    Route::post('/admin/assistants/{assistant}/invitation', [AdminAssistantController::class, 'resendInvitation'])
        ->middleware('throttle:10,1')
        ->name('admin.assistants.invitation');
    Route::post('/admin/assistants/{assistant}/assignments', [AdminAssistantController::class, 'storeAssignments'])
        ->name('admin.assistants.assignments.store');
    Route::delete('/admin/assistants/{assistant}/assignments/{doctor}', [AdminAssistantController::class, 'destroyAssignment'])
        ->name('admin.assistants.assignments.destroy');
});
