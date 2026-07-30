<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('consultation-types', [CatalogController::class, 'consultationTypes']);
    Route::get('legal-services', [CatalogController::class, 'legalServices']);
    Route::get('availability', [ConsultationController::class, 'availability']);

    Route::post('consultations/draft', [ConsultationController::class, 'store']);
    Route::get('consultations/{consultation}', [ConsultationController::class, 'show']);
    Route::post('consultations/{consultation}/complete', [ConsultationController::class, 'complete']);
    Route::post('consultations/{consultation}/reschedule', [ConsultationController::class, 'reschedule']);

    Route::post('payments/converge/confirmation', [PaymentWebhookController::class, 'confirmation']);
    Route::post('payments/converge/webhook', [PaymentWebhookController::class, 'converge']);
});
