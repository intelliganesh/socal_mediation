<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\QuestionnaireController;
use App\Http\Controllers\Api\V1\SimulatedPaymentController;
use App\Http\Controllers\Payments\ConvergeCheckoutController;
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
    Route::get('consultations/{consultation}/reschedule-status', [ConsultationController::class, 'rescheduleStatus']);
    Route::get('consultations/{consultation}', [ConsultationController::class, 'show']);
    Route::post('consultations/{consultation}/complete', [ConsultationController::class, 'complete']);
    Route::post('consultations/{consultation}/reschedule', [ConsultationController::class, 'reschedule']);
    Route::post('free-intro-slots/{scheduling_token}', [ConsultationController::class, 'scheduleFreeIntroParticipantSlot']);

    Route::get('questionnaires/socal-divorce-intake/{token}', [QuestionnaireController::class, 'showSocalDivorceIntake']);
    Route::post('questionnaires/socal-divorce-intake/{token}', [QuestionnaireController::class, 'storeSocalDivorceIntake']);
    Route::get('questionnaires/socal-party-mediation/{token}', [QuestionnaireController::class, 'showSocalPartyMediation']);
    Route::post('questionnaires/socal-party-mediation/{token}', [QuestionnaireController::class, 'storeSocalPartyMediation']);
    Route::get('questionnaires/legal-initial-intake/{token}', [QuestionnaireController::class, 'showLegalInitialIntake']);
    Route::post('questionnaires/legal-initial-intake/{token}', [QuestionnaireController::class, 'storeLegalInitialIntake']);
    Route::get('agreements/{token}', [QuestionnaireController::class, 'showAgreement']);
    Route::post('agreements/{token}', [QuestionnaireController::class, 'acceptAgreement']);

    Route::post('payments/converge/confirmation', [PaymentWebhookController::class, 'confirmation']);
    Route::post('payments/converge/webhook', [PaymentWebhookController::class, 'converge']);
    Route::post('payments/converge/return', [ConvergeCheckoutController::class, 'return'])
        ->name('payments.converge.return');
    Route::post('testing/payments/{payment_request_uuid}/complete', [SimulatedPaymentController::class, 'complete']);
});
