<?php

use App\Http\Controllers\Admin\AdminAppointmentController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDoctorController;
use App\Http\Controllers\Admin\AdminSpecialtyController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Doctor\DoctorAppointmentController;
use App\Http\Controllers\Doctor\DoctorAvailabilityController;
use App\Http\Controllers\Doctor\DoctorProfileController;
use App\Http\Controllers\Doctor\DoctorSlotController;
use App\Http\Controllers\DoctorDirectoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/doctors', [DoctorDirectoryController::class, 'index'])->name('doctors.index');
Route::get('/doctors/{doctor}', [DoctorDirectoryController::class, 'show'])->name('doctors.show');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->put('/profile/password', [ProfileController::class, 'updatePassword'])
    ->name('profile.password.update');

Route::middleware(['auth', 'role:patient'])->group(function () {
    Route::get('/patient/dashboard', [AppointmentController::class, 'index'])->name('patient.dashboard');
    Route::get('/patient/profile', [ProfileController::class, 'patient'])->name('patient.profile');
    Route::put('/patient/profile', [ProfileController::class, 'updatePatient'])->name('patient.profile.update');
    Route::delete('/patient/account', [ProfileController::class, 'deactivate'])->name('patient.account.deactivate');
    Route::get('/patient/appointments', [AppointmentController::class, 'index'])->name('patient.appointments.index');
    Route::get('/patient/appointments/{appointment}', [AppointmentController::class, 'show'])
        ->name('patient.appointments.show');
    Route::get('/appointment-slots/{slot}/confirm', [AppointmentController::class, 'confirm'])
        ->name('appointment-slots.confirm');
    Route::post('/appointment-slots/{slot}/book', [AppointmentController::class, 'store'])
        ->name('appointment-slots.book');
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
        ->name('appointments.cancel');
});

Route::middleware(['auth', 'role:doctor'])->group(function () {
    Route::get('/doctor/dashboard', [DoctorProfileController::class, 'show'])->name('doctor.dashboard');
    Route::put('/doctor/profile', [DoctorProfileController::class, 'update'])->name('doctor.profile.update');
    Route::put('/doctor/account', [DoctorProfileController::class, 'updateAccount'])->name('doctor.account.update');
    Route::post('/doctor/availability', [DoctorAvailabilityController::class, 'store'])->name('doctor.availability.store');
    Route::patch('/doctor/availability/{availability}', [DoctorAvailabilityController::class, 'update'])
        ->name('doctor.availability.update');
    Route::delete('/doctor/availability/{availability}', [DoctorAvailabilityController::class, 'destroy'])
        ->name('doctor.availability.destroy');
    Route::post('/doctor/slots/generate', [DoctorSlotController::class, 'store'])->name('doctor.slots.generate');
    Route::get('/doctor/appointments', [DoctorAppointmentController::class, 'index'])
        ->name('doctor.appointments.index');
    Route::get('/doctor/appointments/{appointment}', [DoctorAppointmentController::class, 'show'])
        ->name('doctor.appointments.show');
    Route::post('/doctor/appointments/{appointment}/confirm', [DoctorAppointmentController::class, 'confirm'])
        ->name('doctor.appointments.confirm');
    Route::post('/doctor/appointments/{appointment}/reject', [DoctorAppointmentController::class, 'reject'])
        ->name('doctor.appointments.reject');
    Route::post('/doctor/appointments/{appointment}/start', [DoctorAppointmentController::class, 'start'])
        ->name('doctor.appointments.start');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
    Route::get('/admin/doctors', [AdminDoctorController::class, 'index'])->name('admin.doctors.index');
    Route::get('/admin/doctors/{doctor}', [AdminDoctorController::class, 'show'])->name('admin.doctors.show');
    Route::patch('/admin/doctors/{doctor}', [AdminDoctorController::class, 'update'])->name('admin.doctors.update');
    Route::get('/admin/specialties', [AdminSpecialtyController::class, 'index'])->name('admin.specialties.index');
    Route::post('/admin/specialties', [AdminSpecialtyController::class, 'store'])->name('admin.specialties.store');
    Route::patch('/admin/specialties/{specialty}', [AdminSpecialtyController::class, 'update'])->name('admin.specialties.update');
    Route::delete('/admin/specialties/{specialty}', [AdminSpecialtyController::class, 'destroy'])->name('admin.specialties.destroy');
    Route::get('/admin/appointments', [AdminAppointmentController::class, 'index'])->name('admin.appointments.index');
    Route::get('/admin/appointments/{appointment}', [AdminAppointmentController::class, 'show'])->name('admin.appointments.show');
});
