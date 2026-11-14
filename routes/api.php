<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BillingOpsController;
use App\Http\Controllers\Api\V1\CitizenExtrasController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\MatterWorkController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OdrController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\PracticeAdminController;
use App\Http\Controllers\Api\V1\PracticeController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\RazorpayWebhookController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('ping', PingController::class)->name('api.v1.ping');
// Signed by Razorpay, not by a user. Returns 404 unless Razorpay is the payment driver.
Route::post('webhooks/razorpay', RazorpayWebhookController::class)->middleware('throttle:120,1')->name('api.v1.webhooks.razorpay');
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

        // A closing practice's export, for its named recipient.
        Route::get('practice-exports', [CitizenExtrasController::class, 'practiceExports']);
        Route::get('practice-exports/{offboarding}/download', [CitizenExtrasController::class, 'downloadPracticeExport'])->middleware('throttle:auth');

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
            Route::post('odr/disputes/{dispute}/sessions/{session}/meeting', [OdrController::class, 'meeting']);
            Route::post('odr/disputes/{dispute}/counsel', [OdrController::class, 'inviteCounsel']);
            Route::delete('odr/disputes/{dispute}/counsel/{participant}', [OdrController::class, 'removeCounsel']);
            Route::post('odr/disputes/{dispute}/withdraw', [OdrController::class, 'withdraw']);

            // Personal workspace extras (2026-11-10)
            Route::post('practices', [CitizenExtrasController::class, 'openPractice']);
            Route::post('consultations/{consultation}/cancel', [CitizenExtrasController::class, 'cancelConsultation'])->middleware('pn.can:consultations.request');
            Route::get('vault/folders', [DocumentController::class, 'vaultFolders']);
            Route::post('vault/folders', [DocumentController::class, 'storeVaultFolder']);
            Route::post('vault/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
            Route::post('vault/documents/{document}/shares', [DocumentController::class, 'share']);
            Route::delete('vault/shares/{share}', [DocumentController::class, 'revokeShare']);
            Route::get('forum/mine', [CitizenExtrasController::class, 'myQuestions'])->middleware('pn.can:forum.questions.create');
            Route::put('forum/questions/{question}', [CitizenExtrasController::class, 'updateQuestion'])->middleware('pn.can:forum.questions.update_own');
            Route::put('forum/answers/{answer}', [CitizenExtrasController::class, 'updateAnswer'])->middleware('pn.can:forum.answers.update_own');
            Route::post('forum/comments', [CitizenExtrasController::class, 'comment'])->middleware('pn.can:forum.comments.create');
            Route::get('privacy/export', [CitizenExtrasController::class, 'exportMyData'])->middleware('throttle:auth');
            Route::post('privacy/requests/{privacyRequest}/withdraw', [CitizenExtrasController::class, 'withdrawPrivacyRequest']);
            Route::get('emergency/assigned', [CitizenExtrasController::class, 'assignedEmergencies']);
            Route::put('emergency/responder', [CitizenExtrasController::class, 'responderAvailability']);
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

            // Practice extras (2026-11-10): the same checks as the matching web routes.
            Route::delete('clients/{client}', [MatterWorkController::class, 'destroyClient'])->middleware('pn.can:clients.view_all|clients.view_assigned');
            Route::middleware('pn.can:matters.view_all|matters.view_team|matters.view_assigned')->group(function () {
                Route::delete('matters/{matter}', [MatterWorkController::class, 'destroyMatter']);
                Route::post('matters/{matter}/close', [MatterWorkController::class, 'close']);
                Route::post('matters/{matter}/reopen', [MatterWorkController::class, 'reopen']);
                Route::post('matters/{matter}/archive', [MatterWorkController::class, 'archive']);
                Route::get('matters/{matter}/allocation', [MatterWorkController::class, 'allocation']);
                Route::get('matters/{matter}/assignments', [MatterWorkController::class, 'assignments']);
                Route::delete('matters/{matter}/assignments/{assignment}', [MatterWorkController::class, 'unassign']);
                Route::put('matters/{matter}/billing-restriction', [MatterWorkController::class, 'restriction']);
                Route::get('matters/{matter}/parties', [MatterWorkController::class, 'parties']);
                Route::post('matters/{matter}/parties', [MatterWorkController::class, 'storeParty']);
                Route::post('matters/{matter}/tasks', [MatterWorkController::class, 'storeTask']);
                Route::delete('notes/{note}', [MatterWorkController::class, 'destroyNote']);
                Route::delete('hearings/{hearing}', [MatterWorkController::class, 'destroyHearing']);
            });
            Route::get('calendar', [MatterWorkController::class, 'calendar'])->middleware('pn.can:calendar.firm.view');
            Route::get('diary/entries', [MatterWorkController::class, 'diaryEntries']);
            Route::post('diary/entries', [MatterWorkController::class, 'storeDiaryEntry']);
            Route::delete('diary/entries/{entry}', [MatterWorkController::class, 'destroyDiaryEntry']);
            Route::post('diary/conflicts/{conflict}/resolve', [MatterWorkController::class, 'resolveConflict']);

            Route::middleware('pn.can:billing.invoices.view')->group(function () {
                Route::delete('invoices/{invoice}', [BillingOpsController::class, 'destroyDraft']);
                Route::delete('invoices/{invoice}/lines/{line}', [BillingOpsController::class, 'removeLine']);
                Route::post('invoices/{invoice}/schedule-items', [BillingOpsController::class, 'addScheduleItem']);
                Route::post('invoices/{invoice}/retainers', [BillingOpsController::class, 'addRetainer']);
                Route::post('invoices/{invoice}/cancel', [BillingOpsController::class, 'cancel']);
                Route::post('invoices/{invoice}/credit-notes', [BillingOpsController::class, 'creditNote']);
                Route::post('invoices/{invoice}/payment-link', [BillingOpsController::class, 'paymentLink']);
                Route::get('invoices/{invoice}/payments', [BillingOpsController::class, 'payments']);
                Route::post('payments/{payment}/reverse', [BillingOpsController::class, 'reversePayment']);
            });
            Route::get('reports', [BillingOpsController::class, 'reports'])->middleware('pn.can:billing.reports.view');
            Route::middleware('pn.can:billing.fee_arrangements.view')->group(function () {
                Route::get('fees', [BillingOpsController::class, 'fees']);
                Route::post('fees', [BillingOpsController::class, 'storeFee']);
                Route::post('fees/{arrangement}/end', [BillingOpsController::class, 'endFee']);
                Route::post('fees/{arrangement}/items', [BillingOpsController::class, 'storeFeeItem']);
                Route::delete('fee-items/{item}', [BillingOpsController::class, 'destroyFeeItem']);
            });
            Route::middleware('pn.can:billing.retainer_plans.manage|billing.retainer_work.manage|billing.retainer_work.update_assigned')->group(function () {
                Route::get('retainers', [BillingOpsController::class, 'retainers']);
                Route::post('retainers/plans', [BillingOpsController::class, 'storePlan']);
                Route::post('retainers/plans/{plan}/toggle', [BillingOpsController::class, 'togglePlan']);
                Route::post('retainers/work', [BillingOpsController::class, 'storeWork']);
                Route::put('retainers/work/{item}', [BillingOpsController::class, 'updateWork']);
            });
            Route::get('time-entries/team', [BillingOpsController::class, 'teamTime']);
            Route::delete('time-entries/{entry}', [BillingOpsController::class, 'destroyTime']);
            Route::post('time-entries/{entry}/approve', [BillingOpsController::class, 'approveTime']);
            Route::get('expenses', [BillingOpsController::class, 'expenses']);
            Route::post('expenses', [BillingOpsController::class, 'storeExpense']);

            Route::middleware('pn.can:consultations.inbox.view_all|consultations.inbox.view_own')->group(function () {
                Route::get('practice/consultations', [PracticeAdminController::class, 'consultations']);
                Route::get('practice/consultations/{consultation}', [PracticeAdminController::class, 'consultation']);
                Route::post('practice/consultations/{consultation}/respond', [PracticeAdminController::class, 'respondConsultation']);
                Route::post('practice/consultations/{consultation}/messages', [PracticeAdminController::class, 'messageConsultation']);
                Route::put('practice/consultations/{consultation}/notes', [PracticeAdminController::class, 'consultationNotes']);
                Route::post('practice/consultations/{consultation}/convert', [PracticeAdminController::class, 'convertConsultation']);
            });

            Route::middleware('pn.can:tenant_documents.view')->group(function () {
                Route::post('practice/documents', [DocumentController::class, 'storePractice']);
                Route::post('clients/{client}/documents', [DocumentController::class, 'storeForClient']);
                Route::post('practice/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
                Route::delete('practice/document-shares/{share}', [DocumentController::class, 'revokeShare']);
                Route::get('practice/folders', [DocumentController::class, 'practiceFolders']);
                Route::post('practice/folders', [DocumentController::class, 'storePracticeFolder']);
            });

            Route::middleware('pn.can:knowledge.view|knowledge.contribute|knowledge.approve')->group(function () {
                Route::post('knowledge', [PracticeAdminController::class, 'storeKnowledge']);
                Route::post('knowledge/{item}/approve', [PracticeAdminController::class, 'approveKnowledge']);
                Route::post('knowledge/{item}/archive', [PracticeAdminController::class, 'archiveKnowledge']);
                Route::get('knowledge/{item}/download', [PracticeAdminController::class, 'downloadKnowledge']);
            });
            Route::middleware(['pn.can:knowledge.contribute|knowledge.approve', 'throttle:20,1'])->group(function () {
                Route::post('ai/draft', [PracticeAdminController::class, 'aiDraft']);
                Route::post('ai/summary', [PracticeAdminController::class, 'aiSummary']);
            });

            Route::get('practice/settings', [PracticeAdminController::class, 'settings']);
            Route::put('practice/settings', [PracticeAdminController::class, 'updateSettings']);
            Route::post('practice/settings/branches', [PracticeAdminController::class, 'storeBranch']);
            Route::post('practice/settings/groups', [PracticeAdminController::class, 'storeGroup']);
            Route::post('practice/settings/availability', [PracticeAdminController::class, 'storeAvailability']);
            Route::put('practice/settings/practice-areas', [PracticeAdminController::class, 'practiceAreas']);
            Route::put('team/{member}/capacity', [PracticeAdminController::class, 'capacity'])->middleware('pn.can:tenant.members.update');
            Route::get('practice/audit', [PracticeAdminController::class, 'audit'])->middleware('pn.can:tenant.audit.view');
        });
    });
});
