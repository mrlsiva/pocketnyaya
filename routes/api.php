<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OdrController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\PracticeController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('ping', PingController::class)->name('api.v1.ping');
Route::get('directory/advocates', [PublicController::class, 'advocates']);
Route::get('directory/advocates/{advocate}', [PublicController::class, 'advocate']);
Route::get('forum/questions', [CommunityController::class, 'questions']);
Route::get('forum/questions/{question}', [CommunityController::class, 'question_'])->whereUuid('question');

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum', 'active', 'api.ability'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:auth');

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);

    Route::middleware('verified')->group(function () {
        Route::get('me/workspaces', [MeController::class, 'workspaces']);
        Route::post('me/devices', [CommunityController::class, 'registerDevice']);
        Route::delete('me/devices/{device}', [CommunityController::class, 'removeDevice']);
        Route::get('me/notifications', [CommunityController::class, 'notifications']);
        Route::post('me/notifications/{notification}/read', [CommunityController::class, 'readNotification']);

        // A received share needs no firm context, and the policy decides per document.
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        Route::middleware('citizen.context')->group(function () {
            Route::get('me/verification', [VerificationController::class, 'show']);
            Route::post('me/verification/advocate', [VerificationController::class, 'submitAdvocate']);
            Route::post('me/verification/mediator', [VerificationController::class, 'applyMediator']);
            Route::post('me/verification/{verificationRequest}/documents', [VerificationController::class, 'uploadDocument']);
            Route::put('me/directory-listing', [VerificationController::class, 'listing']);

            Route::get('consultations', [PublicController::class, 'consultations']);
            Route::post('consultations', [PublicController::class, 'requestConsultation'])->middleware('pn.can:consultations.request');
            Route::get('consultations/{consultation}', [PublicController::class, 'consultation_']);
            Route::post('consultations/{consultation}/messages', [PublicController::class, 'message'])->middleware('pn.can:consultations.messages.send_own');
            Route::post('emergency-requests', [PublicController::class, 'emergency'])->middleware(['pn.can:emergency.request', 'throttle:auth']);

            Route::get('vault/documents', [DocumentController::class, 'vault']);
            Route::post('vault/documents', [DocumentController::class, 'storeVault']);

            Route::post('forum/questions', [CommunityController::class, 'ask'])->middleware('pn.can:forum.questions.create');
            Route::post('forum/questions/{question}/answers', [CommunityController::class, 'answer'])->middleware('pn.can:forum.answers.create');
            Route::post('forum/reports', [CommunityController::class, 'report'])->middleware('pn.can:forum.reports.create');

            Route::get('privacy/consents', [CommunityController::class, 'consents']);
            Route::post('privacy/consents/{purpose}', [CommunityController::class, 'updateConsent']);
            Route::get('privacy/requests', [CommunityController::class, 'privacyRequests']);
            Route::post('privacy/requests', [CommunityController::class, 'storePrivacyRequest']);

            Route::get('odr/disputes', [OdrController::class, 'index']);
            Route::post('odr/disputes', [OdrController::class, 'store'])->middleware('pn.can:odr.disputes.create');
            Route::get('odr/disputes/{dispute}', [OdrController::class, 'show']);
            Route::post('odr/disputes/{dispute}/respondent', [OdrController::class, 'invite']);
            Route::post('odr/disputes/{dispute}/messages', [OdrController::class, 'message']);
            Route::post('odr/disputes/{dispute}/offers', [OdrController::class, 'offer']);
            Route::post('odr/disputes/{dispute}/offers/{offer}', [OdrController::class, 'respondOffer']);
            Route::post('odr/disputes/{dispute}/settlements/{settlement}', [OdrController::class, 'respondSettlement']);
            Route::get('odr/disputes/{dispute}/documents/{document}', [OdrController::class, 'download']);
            Route::post('odr/disputes/{dispute}/evidence', [OdrController::class, 'evidence']);
            Route::post('odr/invitations/{token}/accept', [OdrController::class, 'acceptInvitation'])->middleware('throttle:auth');
            Route::post('odr/invitations/{token}/decline', [OdrController::class, 'declineInvitation'])->middleware('throttle:auth');

            // Mediator tools
            Route::get('odr/assignments', [OdrController::class, 'assignments']);
            Route::post('odr/assignments/{assignment}', [OdrController::class, 'respondAssignment']);
            Route::post('odr/disputes/{dispute}/sessions', [OdrController::class, 'schedule']);
            Route::put('odr/disputes/{dispute}/sessions/{session}', [OdrController::class, 'updateSession']);
            Route::post('odr/disputes/{dispute}/settlements', [OdrController::class, 'generate']);
            Route::post('odr/disputes/{dispute}/settlements/{settlement}/void', [OdrController::class, 'voidSettlement']);
            Route::post('odr/disputes/{dispute}/close', [OdrController::class, 'close']);
        });

        Route::middleware('tenant.context')->group(function () {
            Route::middleware('pn.can:tenant.members.view')->group(function () {
                Route::get('team', [TeamController::class, 'index']);
                Route::post('team/invitations', [TeamController::class, 'invite']);
                Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation']);
                Route::put('team/{member}/role', [TeamController::class, 'assignRole']);
                Route::delete('team/{member}', [TeamController::class, 'deactivate']);
            });
            Route::middleware('pn.can:clients.view_all|clients.view_assigned')->group(function () {
                Route::get('clients', [PracticeController::class, 'clients']);
                Route::post('clients', [PracticeController::class, 'storeClient']);
                Route::get('clients/{client}', [PracticeController::class, 'showClient']);
                Route::patch('clients/{client}', [PracticeController::class, 'updateClient']);
            });
            Route::middleware('pn.can:matters.view_all|matters.view_team|matters.view_assigned')->group(function () {
                Route::get('matters', [PracticeController::class, 'matters']);
                Route::post('matters', [PracticeController::class, 'storeMatter']);
                Route::get('matters/{matter}', [PracticeController::class, 'showMatter']);
                Route::patch('matters/{matter}', [PracticeController::class, 'updateMatter']);
                Route::post('matters/{matter}/assignments', [PracticeController::class, 'assign']);
                Route::post('matters/{matter}/notes', [PracticeController::class, 'storeNote']);
                Route::post('matters/{matter}/hearings', [PracticeController::class, 'storeHearing']);
                Route::post('hearings/{hearing}/outcome', [PracticeController::class, 'recordOutcome']);
            });
            Route::middleware('pn.can:billing.invoices.view')->group(function () {
                Route::get('invoices', [BillingController::class, 'index']);
                Route::post('invoices', [BillingController::class, 'store']);
                Route::get('invoices/{invoice}', [BillingController::class, 'show']);
                Route::post('invoices/{invoice}/lines', [BillingController::class, 'addLine']);
                Route::post('invoices/{invoice}/time', [BillingController::class, 'addTime']);
                Route::post('invoices/{invoice}/expenses', [BillingController::class, 'addExpenses']);
                Route::get('invoices/{invoice}/pdf', [BillingController::class, 'pdf']);
                Route::post('invoices/{invoice}/issue', [BillingController::class, 'issue']);
                Route::post('invoices/{invoice}/payments', [BillingController::class, 'pay']);
            });
            Route::get('time-entries', [BillingController::class, 'time']);
            Route::post('time-entries', [BillingController::class, 'logTime']);
            Route::get('knowledge', [CommunityController::class, 'knowledge'])->middleware('pn.can:knowledge.view|knowledge.contribute|knowledge.approve');
            Route::middleware('pn.can:tenant_documents.view')->group(function () {
                Route::get('practice/documents', [DocumentController::class, 'practice']);
                Route::post('matters/{matter}/documents', [DocumentController::class, 'storeForMatter']);
            });
            Route::get('tasks', [PracticeController::class, 'tasks']);
            Route::patch('tasks/{task}', [PracticeController::class, 'updateTask']);
            Route::get('diary', [PracticeController::class, 'diary']);
            Route::get('diary/conflicts', [PracticeController::class, 'conflicts']);
        });
    });
});
