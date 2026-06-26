<?php

use App\Http\Controllers\Mobile\MiriamMobileController;
use App\Http\Middleware\AuthenticateMiriamMobile;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function (): void {
    Route::post('/login', [MiriamMobileController::class, 'login'])->name('api.mobile.login');

    Route::middleware(AuthenticateMiriamMobile::class)->group(function (): void {
        Route::get('/me', [MiriamMobileController::class, 'me'])->name('api.mobile.me');
        Route::post('/logout', [MiriamMobileController::class, 'logout'])->name('api.mobile.logout');
        Route::post('/miriam/chat', [MiriamMobileController::class, 'chat'])->name('api.mobile.miriam.chat');
        Route::get('/reminders', [MiriamMobileController::class, 'reminders'])->name('api.mobile.reminders');
        Route::post('/reminders/{reminder}/done', [MiriamMobileController::class, 'reminderDone'])->name('api.mobile.reminders.done');
        Route::post('/reminders/{reminder}/snooze', [MiriamMobileController::class, 'reminderSnooze'])->name('api.mobile.reminders.snooze');
        Route::post('/reminders/{reminder}/cancel', [MiriamMobileController::class, 'reminderCancel'])->name('api.mobile.reminders.cancel');
        Route::get('/medication/status', [MiriamMobileController::class, 'medicationStatus'])->name('api.mobile.medication.status');
        Route::post('/medication/{doseLog}/taken', [MiriamMobileController::class, 'medicationTaken'])->name('api.mobile.medication.taken');
        Route::post('/medication/{doseLog}/snooze', [MiriamMobileController::class, 'medicationSnooze'])->name('api.mobile.medication.snooze');
        Route::post('/medication/{doseLog}/skip', [MiriamMobileController::class, 'medicationSkip'])->name('api.mobile.medication.skip');
        Route::get('/agenda/today', [MiriamMobileController::class, 'agendaToday'])->name('api.mobile.agenda.today');
        Route::get('/agenda/tomorrow', [MiriamMobileController::class, 'agendaTomorrow'])->name('api.mobile.agenda.tomorrow');
        Route::get('/agenda/upcoming', [MiriamMobileController::class, 'agendaUpcoming'])->name('api.mobile.agenda.upcoming');
        Route::get('/development/status', [MiriamMobileController::class, 'developmentStatus'])->name('api.mobile.development.status');
    });
});
