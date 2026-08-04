<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\ConsultationAdminController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Payments\ConvergeCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::get('/login', function () {
    return redirect()->route('admin.login');
})->name('login');

Route::get('payments/{paymentRequest}/checkout', [ConvergeCheckoutController::class, 'checkout'])
    ->middleware('signed')
    ->name('payments.checkout');

Route::prefix('admin')->name('admin.')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('consultations', [ConsultationAdminController::class, 'index'])->name('consultations.index');
        Route::get('consultations/{consultation}', [ConsultationAdminController::class, 'show'])->name('consultations.show');
        Route::post('consultations/{consultation}/payment-links', [ConsultationAdminController::class, 'sendPaymentLinks'])->name('consultations.payment-links');
        Route::post('consultations/{consultation}/reminder', [ConsultationAdminController::class, 'sendReminder'])->name('consultations.reminder');
        Route::post('consultations/{consultation}/zoom-link', [ConsultationAdminController::class, 'resendZoomLink'])->name('consultations.zoom-link');
        Route::post('consultations/{consultation}/regenerate-zoom', [ConsultationAdminController::class, 'regenerateZoomLink'])->name('consultations.regenerate-zoom');
        Route::post('consultations/{consultation}/cancel', [ConsultationAdminController::class, 'cancel'])->name('consultations.cancel');
        Route::post('consultations/{consultation}/reschedule', [ConsultationAdminController::class, 'reschedule'])->name('consultations.reschedule');
        Route::post('consultations/{consultation}/sync-outlook', [ConsultationAdminController::class, 'syncOutlook'])->name('consultations.sync-outlook');
        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::post('calendar/sync', [CalendarController::class, 'sync'])->name('calendar.sync');
    });
});
