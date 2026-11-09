<?php

use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\MfaController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\SessionController;
use App\Http\Controllers\Web\Billing\FeeController;
use App\Http\Controllers\Web\Billing\InvoiceController;
use App\Http\Controllers\Web\Billing\RetainerController;
use App\Http\Controllers\Web\Billing\TimeExpenseController;
use App\Http\Controllers\Web\Citizen\AdvocateProfileController;
use App\Http\Controllers\Web\Citizen\PracticeSetupController;
use App\Http\Controllers\Web\ConsultationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectoryController;
use App\Http\Controllers\Web\Documents\DocumentController;
use App\Http\Controllers\Web\EmergencyController;
use App\Http\Controllers\Web\ForumController;
use App\Http\Controllers\Web\InvitationController;
use App\Http\Controllers\Web\OdrController;
use App\Http\Controllers\Web\OffboardingExportController;
use App\Http\Controllers\Web\Practice\ClientController;
use App\Http\Controllers\Web\Practice\DiaryController;
use App\Http\Controllers\Web\Practice\KnowledgeController;
use App\Http\Controllers\Web\Practice\MatterController;
use App\Http\Controllers\Web\Practice\SettingsController;
use App\Http\Controllers\Web\Practice\TeamController;
use App\Http\Controllers\Web\PrivacyController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\Staff\AdminController;
use App\Http\Controllers\Web\Staff\ComplianceController;
use App\Http\Controllers\Web\Staff\IntegrationController;
use App\Http\Controllers\Web\Staff\ModerationController;
use App\Http\Controllers\Web\Staff\OdrIntakeController;
use App\Http\Controllers\Web\Staff\OffboardingController;
use App\Http\Controllers\Web\Staff\StaffTeamController;
use App\Http\Controllers\Web\Staff\TaxRuleController;
use App\Http\Controllers\Web\Staff\VerificationQueueController;
use App\Http\Controllers\Web\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('advocates', [DirectoryController::class, 'index'])->name('directory.index');
Route::get('advocates/{advocate}', [DirectoryController::class, 'show'])->name('directory.show');
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::get('forum', [ForumController::class, 'index'])->name('forum.index');
Route::get('forum/{question}', [ForumController::class, 'show'])->whereUuid('question')->name('forum.show');
Route::get('disclaimers/{code}', [PrivacyController::class, 'disclaimer'])->name('disclaimers.show');
Route::get('odr/invitations/{token}', [OdrController::class, 'invitation'])->name('odr.invitations.show');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:auth');
    Route::get('login', [SessionController::class, 'create'])->name('login');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:auth');
    Route::get('forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:auth')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth')->name('password.update');
});

Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:auth'])->name('verification.verify');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:auth')->name('verification.send');

    Route::middleware('verified')->group(function () {
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        Route::post('odr/invitations/{token}/accept', [OdrController::class, 'acceptInvitation'])->name('odr.invitations.accept');
        Route::post('odr/invitations/{token}/decline', [OdrController::class, 'declineInvitation'])->name('odr.invitations.decline');
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('practice-exports', [OffboardingExportController::class, 'mine'])->name('offboarding.mine');
        Route::get('practice-exports/{offboarding}/download', [OffboardingExportController::class, 'download'])->middleware('throttle:auth')->name('offboarding.download');
        Route::post('tenant/switch', [WorkspaceController::class, 'switch'])->name('tenant.switch');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');

        Route::get('mfa/setup', [MfaController::class, 'setupForm'])->name('mfa.setup');
        Route::post('mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:auth');
        Route::get('mfa/challenge', [MfaController::class, 'challengeForm'])->name('mfa.challenge');
        Route::post('mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:auth');

        Route::middleware('citizen.context')->prefix('citizen')->name('citizen.')->group(function () {
            Route::get('/', [DashboardController::class, 'citizen'])->name('dashboard');
            Route::get('practice/new', [PracticeSetupController::class, 'create'])->name('practice.create');
            Route::post('practice', [PracticeSetupController::class, 'store'])->name('practice.store');

            Route::get('vault', [DocumentController::class, 'vaultIndex'])->name('vault.index');
            Route::post('vault', [DocumentController::class, 'vaultStore'])->name('vault.store');
            Route::post('vault/folders', [DocumentController::class, 'storeFolder'])->name('vault.folders.store');
            Route::get('vault/{document}', [DocumentController::class, 'show'])->name('vault.show');
            Route::get('vault/{document}/download', [DocumentController::class, 'download'])->name('vault.download');
            Route::post('vault/{document}/versions', [DocumentController::class, 'storeVersion'])->name('vault.versions.store');
            Route::delete('vault/{document}', [DocumentController::class, 'destroy'])->name('vault.destroy');
            Route::post('vault/{document}/shares', [DocumentController::class, 'share'])->name('vault.shares.store');
            Route::delete('vault/shares/{share}', [DocumentController::class, 'revokeShare'])->name('vault.shares.revoke');

            Route::get('advocate-profile', [AdvocateProfileController::class, 'edit'])->name('advocate.edit');
            Route::put('advocate-profile', [AdvocateProfileController::class, 'update'])->name('advocate.update');
            Route::post('advocate-profile/verification', [AdvocateProfileController::class, 'submit'])->name('advocate.submit');
            Route::put('advocate-profile/listing', [AdvocateProfileController::class, 'listing'])->name('advocate.listing');
            Route::put('advocate-profile/responder', [AdvocateProfileController::class, 'joinResponders'])->name('advocate.responder');
            Route::post('mediator-application', [AdvocateProfileController::class, 'applyMediator'])->name('mediator.apply');
            Route::post('verification/{verificationRequest}/documents', [AdvocateProfileController::class, 'uploadDocument'])->name('verification.documents.store');
            Route::get('verification/documents/{document}/download', [AdvocateProfileController::class, 'downloadDocument'])->name('verification.documents.download');

            Route::get('consultations', [ConsultationController::class, 'index'])->name('consultations.index');
            Route::get('advocates/{advocate}/consult', [ConsultationController::class, 'create'])->middleware('pn.can:consultations.request')->name('consultations.create');
            Route::post('advocates/{advocate}/consult', [ConsultationController::class, 'store'])->middleware('pn.can:consultations.request')->name('consultations.store');
            Route::get('consultations/{consultation}', [ConsultationController::class, 'showCustomer'])->name('consultations.show');
            Route::post('consultations/{consultation}/messages', [ConsultationController::class, 'messageCustomer'])->middleware('pn.can:consultations.messages.send_own')->name('consultations.messages');
            Route::post('consultations/{consultation}/cancel', [ConsultationController::class, 'cancel'])->middleware('pn.can:consultations.request')->name('consultations.cancel');

            Route::get('emergency', [EmergencyController::class, 'create'])->name('emergency.create');
            Route::post('emergency', [EmergencyController::class, 'store'])->middleware(['pn.can:emergency.request', 'throttle:auth'])->name('emergency.store');
            Route::get('emergency/assigned', [EmergencyController::class, 'assigned'])->name('emergency.assigned');

            Route::get('forum/ask', [ForumController::class, 'create'])->middleware('pn.can:forum.questions.create')->name('forum.create');
            Route::post('forum', [ForumController::class, 'store'])->middleware('pn.can:forum.questions.create')->name('forum.store');
            Route::get('forum/mine', [ForumController::class, 'mine'])->middleware('pn.can:forum.questions.create')->name('forum.mine');
            Route::get('forum/{question}/edit', [ForumController::class, 'edit'])->middleware('pn.can:forum.questions.update_own')->name('forum.edit');
            Route::put('forum/{question}', [ForumController::class, 'update'])->middleware('pn.can:forum.questions.update_own')->name('forum.update');
            Route::put('forum/answers/{answer}', [ForumController::class, 'updateAnswer'])->middleware('pn.can:forum.answers.update_own')->name('forum.answers.update');
            Route::post('forum/comments', [ForumController::class, 'comment'])->middleware('pn.can:forum.comments.create')->name('forum.comment');
            Route::post('forum/reports', [ForumController::class, 'report'])->middleware('pn.can:forum.reports.create')->name('forum.report');
            Route::post('forum/{question}/answers', [ForumController::class, 'answer'])->middleware('pn.can:forum.answers.create')->name('forum.answer');

            Route::get('privacy', [PrivacyController::class, 'index'])->name('privacy.index');
            Route::get('privacy/export', [PrivacyController::class, 'export'])->name('privacy.export');
            Route::post('privacy/consents/{purpose}', [PrivacyController::class, 'updateConsent'])->name('privacy.consent');
            Route::post('privacy/requests', [PrivacyController::class, 'storeRequest'])->name('privacy.requests.store');
            Route::post('privacy/requests/{privacyRequest}/withdraw', [PrivacyController::class, 'withdrawRequest'])->name('privacy.requests.withdraw');

            Route::get('odr', [OdrController::class, 'index'])->name('odr.index');
            Route::get('odr/new', [OdrController::class, 'create'])->middleware('pn.can:odr.disputes.create')->name('odr.create');
            Route::post('odr', [OdrController::class, 'store'])->middleware('pn.can:odr.disputes.create')->name('odr.store');
            Route::post('odr/assignments/{assignment}', [OdrController::class, 'respondAssignment'])->name('odr.assignments.respond');
            Route::get('odr/{dispute}', [OdrController::class, 'show'])->name('odr.show');
            Route::post('odr/{dispute}/respondent', [OdrController::class, 'inviteRespondent'])->name('odr.respondent');
            Route::post('odr/{dispute}/counsel', [OdrController::class, 'inviteCounsel'])->name('odr.counsel');
            Route::delete('odr/{dispute}/counsel/{participant}', [OdrController::class, 'removeCounsel'])->name('odr.counsel.remove');
            Route::post('odr/{dispute}/withdraw', [OdrController::class, 'withdraw'])->name('odr.withdraw');
            Route::post('odr/{dispute}/messages', [OdrController::class, 'message'])->name('odr.messages');
            Route::post('odr/{dispute}/evidence', [OdrController::class, 'evidence'])->name('odr.evidence');
            Route::get('odr/{dispute}/documents/{document}', [OdrController::class, 'download'])->name('odr.documents.download');
            Route::post('odr/{dispute}/offers', [OdrController::class, 'offer'])->name('odr.offers');
            Route::post('odr/{dispute}/offers/{offer}', [OdrController::class, 'respondOffer'])->name('odr.offers.respond');
            Route::post('odr/{dispute}/sessions', [OdrController::class, 'schedule'])->name('odr.sessions.store');
            Route::put('odr/{dispute}/sessions/{session}', [OdrController::class, 'updateSession'])->name('odr.sessions.update');
            Route::post('odr/{dispute}/sessions/{session}/meeting', [OdrController::class, 'meeting'])->name('odr.sessions.meeting');
            Route::post('odr/{dispute}/settlements', [OdrController::class, 'generate'])->name('odr.settlements.store');
            Route::post('odr/{dispute}/settlements/{settlement}/void', [OdrController::class, 'voidSettlement'])->name('odr.settlements.void');
            Route::post('odr/{dispute}/settlements/{settlement}/respond', [OdrController::class, 'respondSettlement'])->name('odr.settlements.respond');
            Route::post('odr/{dispute}/close', [OdrController::class, 'close'])->name('odr.close');
        });

        Route::middleware('tenant.context')->prefix('practice')->name('practice.')->group(function () {
            Route::get('/', [DashboardController::class, 'practice'])->name('dashboard');

            Route::middleware('pn.can:clients.view_all|clients.view_assigned')->group(function () {
                Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
                Route::get('clients/new', [ClientController::class, 'create'])->name('clients.create');
                Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
                Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
                Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
                Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
                Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
            });

            Route::middleware('pn.can:matters.view_all|matters.view_team|matters.view_assigned')->group(function () {
                Route::get('matters', [MatterController::class, 'index'])->name('matters.index');
                Route::get('matters/new', [MatterController::class, 'create'])->name('matters.create');
                Route::post('matters', [MatterController::class, 'store'])->name('matters.store');
                Route::get('matters/{matter}', [MatterController::class, 'show'])->name('matters.show');
                Route::get('matters/{matter}/edit', [MatterController::class, 'edit'])->name('matters.edit');
                Route::put('matters/{matter}', [MatterController::class, 'update'])->name('matters.update');
                Route::delete('matters/{matter}', [MatterController::class, 'destroy'])->name('matters.destroy');
                Route::post('matters/{matter}/close', [MatterController::class, 'close'])->name('matters.close');
                Route::post('matters/{matter}/reopen', [MatterController::class, 'reopen'])->name('matters.reopen');
                Route::post('matters/{matter}/archive', [MatterController::class, 'archive'])->name('matters.archive');
                Route::post('matters/{matter}/assignments', [MatterController::class, 'assign'])->name('matters.assign');
                Route::delete('matters/{matter}/assignments/{assignment}', [MatterController::class, 'unassign'])->name('matters.unassign');
                Route::put('matters/{matter}/billing-restriction', [MatterController::class, 'restriction'])->name('matters.restriction');
                Route::post('matters/{matter}/parties', [MatterController::class, 'storeParty'])->name('matters.parties.store');
                Route::post('matters/{matter}/tasks', [MatterController::class, 'storeTask'])->name('matters.tasks.store');
                Route::post('matters/{matter}/notes', [MatterController::class, 'storeNote'])->name('matters.notes.store');
                Route::delete('notes/{note}', [MatterController::class, 'destroyNote'])->name('notes.destroy');
                Route::post('matters/{matter}/hearings', [DiaryController::class, 'storeHearing'])->name('hearings.store');
                Route::post('hearings/{hearing}/outcome', [DiaryController::class, 'recordOutcome'])->name('hearings.outcome');
                Route::delete('hearings/{hearing}', [DiaryController::class, 'destroyHearing'])->name('hearings.destroy');
            });

            Route::put('tasks/{task}/status', [MatterController::class, 'updateTaskStatus'])->name('tasks.status');

            Route::middleware('pn.can:billing.invoices.view')->group(function () {
                Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
                Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
                Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
                Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
                Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'addLine'])->name('invoices.lines.store');
                Route::delete('invoices/{invoice}/lines/{line}', [InvoiceController::class, 'removeLine'])->name('invoices.lines.destroy');
                Route::post('invoices/{invoice}/time', [InvoiceController::class, 'addTime'])->name('invoices.time.store');
                Route::post('invoices/{invoice}/expenses', [InvoiceController::class, 'addExpenses'])->name('invoices.expenses.store');
                Route::post('invoices/{invoice}/schedule-items', [InvoiceController::class, 'addScheduleItem'])->name('invoices.schedule.store');
                Route::post('invoices/{invoice}/retainers', [InvoiceController::class, 'addRetainer'])->name('invoices.retainers.store');
                Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
                Route::post('invoices/{invoice}/credit-notes', [InvoiceController::class, 'creditNote'])->name('invoices.credit');
                Route::post('invoices/{invoice}/payment-link', [InvoiceController::class, 'paymentLink'])->name('invoices.payment_link');
                Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
                Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
                Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
                Route::post('payments/{payment}/reverse', [InvoiceController::class, 'reversePayment'])->name('payments.reverse');
            });
            Route::get('reports', [InvoiceController::class, 'reports'])->middleware('pn.can:billing.reports.view')->name('reports');
            Route::middleware('pn.can:billing.fee_arrangements.view')->group(function () {
                Route::get('fees', [FeeController::class, 'index'])->name('fees.index');
                Route::post('fees', [FeeController::class, 'store'])->name('fees.store');
                Route::post('fees/{arrangement}/end', [FeeController::class, 'end'])->name('fees.end');
                Route::post('fees/{arrangement}/items', [FeeController::class, 'storeItem'])->name('fees.items.store');
                Route::delete('fee-items/{item}', [FeeController::class, 'destroyItem'])->name('fees.items.destroy');
            });
            Route::middleware('pn.can:billing.retainer_plans.manage|billing.retainer_work.manage|billing.retainer_work.update_assigned')->group(function () {
                Route::get('retainers', [RetainerController::class, 'index'])->name('retainers.index');
                Route::post('retainers/plans', [RetainerController::class, 'storePlan'])->name('retainers.plans.store');
                Route::post('retainers/plans/{plan}/toggle', [RetainerController::class, 'togglePlan'])->name('retainers.plans.toggle');
                Route::post('retainers/work', [RetainerController::class, 'storeWork'])->name('retainers.work.store');
                Route::put('retainers/work/{item}', [RetainerController::class, 'updateWork'])->name('retainers.work.update');
            });
            Route::get('time', [TimeExpenseController::class, 'index'])->name('time.index');
            Route::post('time', [TimeExpenseController::class, 'storeTime'])->name('time.store');
            Route::delete('time/{entry}', [TimeExpenseController::class, 'destroyTime'])->name('time.destroy');
            Route::post('time/{entry}/approve', [TimeExpenseController::class, 'approveTime'])->name('time.approve');
            Route::post('expenses', [TimeExpenseController::class, 'storeExpense'])->name('expenses.store');

            Route::middleware('pn.can:consultations.inbox.view_all|consultations.inbox.view_own')->group(function () {
                Route::get('consultations', [ConsultationController::class, 'inbox'])->name('consultations.index');
                Route::get('consultations/{consultation}', [ConsultationController::class, 'showPractice'])->name('consultations.show');
                Route::post('consultations/{consultation}/respond', [ConsultationController::class, 'respond'])->name('consultations.respond');
                Route::post('consultations/{consultation}/messages', [ConsultationController::class, 'messagePractice'])->name('consultations.messages');
                Route::put('consultations/{consultation}/notes', [ConsultationController::class, 'notes'])->name('consultations.notes');
                Route::post('consultations/{consultation}/convert', [ConsultationController::class, 'convert'])->name('consultations.convert');
            });

            Route::middleware('pn.can:tenant.members.view')->group(function () {
                Route::get('team', [TeamController::class, 'index'])->name('team.index');
                Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invite');
                Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
                Route::put('team/{member}/role', [TeamController::class, 'assignRole'])->name('team.role');
                Route::delete('team/{member}', [TeamController::class, 'deactivate'])->name('team.deactivate');
            });

            Route::middleware('pn.can:tenant_documents.view')->group(function () {
                Route::get('documents', [DocumentController::class, 'practiceIndex'])->name('documents.index');
                Route::post('documents', [DocumentController::class, 'practiceStore'])->name('documents.store');
                Route::post('documents/folders', [DocumentController::class, 'storeFolder'])->name('documents.folders.store');
                Route::post('matters/{matter}/documents', [DocumentController::class, 'storeForMatter'])->name('matters.documents.store');
                Route::post('clients/{client}/documents', [DocumentController::class, 'storeForClient'])->name('clients.documents.store');
                Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
                Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
                Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');
                Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
                Route::delete('documents/shares/{share}', [DocumentController::class, 'revokeShare'])->name('documents.shares.revoke');
            });
            Route::middleware('pn.can:knowledge.view|knowledge.contribute|knowledge.approve')->group(function () {
                Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
                Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
                Route::post('knowledge/{item}/approve', [KnowledgeController::class, 'approve'])->name('knowledge.approve');
                Route::post('knowledge/{item}/archive', [KnowledgeController::class, 'archive'])->name('knowledge.archive');
                Route::get('knowledge/{item}/download', [KnowledgeController::class, 'download'])->name('knowledge.download');
            });
            Route::get('audit', [DashboardController::class, 'tenantAudit'])->middleware('pn.can:tenant.audit.view')->name('audit');

            Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [SettingsController::class, 'update'])->middleware('pn.can:tenant.settings.update')->name('settings.update');
            Route::post('settings/branches', [SettingsController::class, 'storeBranch'])->middleware('pn.can:tenant.branches.manage')->name('settings.branches');
            Route::post('settings/groups', [SettingsController::class, 'storeGroup'])->middleware('pn.can:tenant.practice_groups.manage')->name('settings.groups');
            Route::post('settings/availability', [SettingsController::class, 'storeAvailability'])->middleware('pn.can:tenant.availability.manage_own')->name('settings.availability');

            Route::get('diary', [DiaryController::class, 'index'])->name('diary.index');
            Route::post('diary', [DiaryController::class, 'storeEntry'])->name('diary.store');
            Route::delete('diary/{entry}', [DiaryController::class, 'destroyEntry'])->name('diary.destroy');
            Route::get('calendar', [DiaryController::class, 'calendar'])->middleware('pn.can:calendar.firm.view')->name('calendar');
            Route::post('conflicts/{conflict}/resolve', [DiaryController::class, 'resolveConflict'])->name('conflicts.resolve');
        });

        Route::middleware('staff.context')->prefix('staff')->name('staff.')->group(function () {
            Route::get('/', [DashboardController::class, 'staff'])->name('dashboard');

            Route::middleware('pn.can:verification.advocates.review|verification.mediators.review')->group(function () {
                Route::get('verification', [VerificationQueueController::class, 'index'])->name('verification.index');
                Route::get('verification/{verificationRequest}', [VerificationQueueController::class, 'show'])->name('verification.show');
                Route::post('verification/{verificationRequest}/start', [VerificationQueueController::class, 'start'])->name('verification.start');
                Route::post('verification/{verificationRequest}/info', [VerificationQueueController::class, 'info'])->name('verification.info');
                Route::post('verification/{verificationRequest}/decide', [VerificationQueueController::class, 'decide'])
                    ->middleware('pn.can:verification.advocates.decide|verification.mediators.decide')->name('verification.decide');
                Route::get('verification-documents/{document}/download', [VerificationQueueController::class, 'download'])->name('verification.documents.download');
            });

            Route::middleware('pn.can:tax.platform_rules.draft')->group(function () {
                Route::get('tax-rules', [TaxRuleController::class, 'index'])->name('tax.index');
                Route::post('tax-rules', [TaxRuleController::class, 'store'])->name('tax.store');
                Route::post('tax-rules/{rule}/approve', [TaxRuleController::class, 'approve'])->middleware('pn.can:tax.platform_rules.record_approval')->name('tax.approve');
            });

            Route::prefix('admin')->name('admin.')->group(function () {
                Route::get('tenants', [AdminController::class, 'tenants'])->middleware('pn.can:platform.tenants.view_metadata')->name('tenants');
                Route::put('tenants/{tenant}', [AdminController::class, 'updateTenant'])->middleware('pn.can:platform.tenants.suspend')->name('tenants.update');
                Route::get('users', [AdminController::class, 'users'])->middleware('pn.can:platform.users.view_metadata')->name('users');
                Route::put('users/{person}', [AdminController::class, 'updateUser'])->middleware('pn.can:platform.users.suspend')->name('users.update');
                Route::middleware('pn.can:platform.settings.manage')->group(function () {
                    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
                    Route::post('integrations/mask-preview', [IntegrationController::class, 'index'])->name('integrations.mask');
                    Route::get('settings', [AdminController::class, 'settings'])->name('settings');
                    Route::put('settings', [AdminController::class, 'updateSetting'])->name('settings.update');
                });
                Route::middleware('pn.can:platform.subscription_plans.manage')->group(function () {
                    Route::get('plans', [AdminController::class, 'plans'])->name('plans');
                    Route::post('plans', [AdminController::class, 'storePlan'])->name('plans.store');
                    Route::post('plans/{plan}/toggle', [AdminController::class, 'togglePlan'])->name('plans.toggle');
                    Route::put('plans/{plan}/limits', [AdminController::class, 'updatePlanLimits'])->name('plans.limits');
                    Route::put('tenants/{tenant}/plan', [AdminController::class, 'assignPlan'])->name('tenants.plan');
                });
                Route::middleware('pn.can:platform.tenants.close')->group(function () {
                    Route::get('offboarding', [OffboardingController::class, 'index'])->name('offboarding.index');
                    Route::get('tenants/{tenant}/offboarding', [OffboardingController::class, 'create'])->name('offboarding.create');
                    Route::post('tenants/{tenant}/offboarding', [OffboardingController::class, 'store'])->name('offboarding.store');
                    Route::get('offboarding/{offboarding}', [OffboardingController::class, 'show'])->name('offboarding.show');
                    Route::post('offboarding/{offboarding}', [OffboardingController::class, 'act'])->name('offboarding.act');
                });
                Route::middleware('pn.can:platform.staff_roles.assign|platform.superadmin.propose|platform.superadmin.approve')->group(function () {
                    Route::get('team', [StaffTeamController::class, 'index'])->name('team');
                    Route::post('team/compliance-officers', [StaffTeamController::class, 'complianceOfficer'])->name('team.compliance');
                    Route::post('team/super-admin-proposals', [StaffTeamController::class, 'propose'])->name('team.propose');
                    Route::post('team/super-admin-proposals/{person}', [StaffTeamController::class, 'decide'])->name('team.decide');
                    Route::post('team/super-admins/{person}/revoke', [StaffTeamController::class, 'revokeSuperAdmin'])->name('team.revoke');
                });
                Route::middleware('pn.can:platform.reference_data.manage')->group(function () {
                    Route::get('reference', [AdminController::class, 'reference'])->name('reference');
                    Route::post('reference/{type}', [AdminController::class, 'storeReference'])->name('reference.store');
                });
            });

            Route::middleware('pn.can:moderation.queue.view')->group(function () {
                Route::get('moderation', [ModerationController::class, 'index'])->name('moderation.index');
                Route::post('moderation/{case}/decide', [ModerationController::class, 'decide'])->middleware('pn.can:moderation.queue.decide')->name('moderation.decide');
            });
            Route::middleware('pn.can:moderation.rules.manage')->group(function () {
                Route::get('screening-rules', [ModerationController::class, 'rules'])->name('moderation.rules');
                Route::post('screening-rules', [ModerationController::class, 'storeRule'])->name('moderation.rules.store');
                Route::post('screening-rules/{rule}/toggle', [ModerationController::class, 'toggleRule'])->name('moderation.rules.toggle');
            });

            Route::prefix('privacy')->name('privacy.')->group(function () {
                Route::get('requests', [ComplianceController::class, 'requests'])->middleware('pn.can:dpdp.requests.view')->name('requests');
                Route::post('requests/{privacyRequest}', [ComplianceController::class, 'processRequest'])->middleware('pn.can:dpdp.requests.process')->name('requests.process');
                Route::get('retention', [ComplianceController::class, 'retention'])->middleware('pn.can:dpdp.retention_rules.draft|dpdp.retention_rules.record_approval')->name('retention');
                Route::post('retention', [ComplianceController::class, 'storeRetention'])->middleware('pn.can:dpdp.retention_rules.draft')->name('retention.store');
                Route::post('retention/{rule}/approve', [ComplianceController::class, 'approveRetention'])->middleware('pn.can:dpdp.retention_rules.record_approval')->name('retention.approve');
                Route::middleware('pn.can:dpdp.legal_holds.manage')->group(function () {
                    Route::get('holds', [ComplianceController::class, 'holds'])->name('holds');
                    Route::post('holds', [ComplianceController::class, 'storeHold'])->name('holds.store');
                    Route::post('holds/{hold}/release', [ComplianceController::class, 'releaseHold'])->name('holds.release');
                });
                Route::middleware('pn.can:dpdp.breaches.manage')->group(function () {
                    Route::get('breaches', [ComplianceController::class, 'breaches'])->name('breaches');
                    Route::post('breaches', [ComplianceController::class, 'storeBreach'])->name('breaches.store');
                    Route::put('breaches/{incident}', [ComplianceController::class, 'updateBreach'])->name('breaches.update');
                });
                Route::get('policies', [ComplianceController::class, 'policies'])->middleware('pn.can:disclaimers.manage|consent_purposes.manage')->name('policies');
                Route::post('disclaimers', [ComplianceController::class, 'storeDisclaimer'])->middleware('pn.can:disclaimers.manage')->name('disclaimers.store');
                Route::post('purposes', [ComplianceController::class, 'storePurpose'])->middleware('pn.can:consent_purposes.manage')->name('purposes.store');
            });
            Route::get('audit', [ComplianceController::class, 'audit'])->middleware('pn.can:platform.audit.view')->name('audit');

            Route::middleware('pn.can:odr.screening.review|odr.mediators.assign')->group(function () {
                Route::get('odr', [OdrIntakeController::class, 'index'])->name('odr.index');
                Route::post('odr/{dispute}/review', [OdrIntakeController::class, 'review'])->middleware('pn.can:odr.screening.review')->name('odr.review');
                Route::post('odr/{dispute}/assign', [OdrIntakeController::class, 'assign'])->middleware('pn.can:odr.mediators.assign')->name('odr.assign');
            });
            Route::middleware('pn.can:odr.templates.manage')->group(function () {
                Route::get('odr-templates', [OdrIntakeController::class, 'templates'])->name('odr.templates');
                Route::post('odr-templates', [OdrIntakeController::class, 'storeTemplate'])->name('odr.templates.store');
                Route::put('odr-templates/{template}', [OdrIntakeController::class, 'updateTemplate'])->name('odr.templates.update');
            });

            Route::middleware('pn.can:emergency.triage')->group(function () {
                Route::get('emergency', [EmergencyController::class, 'queue'])->name('emergency.index');
                Route::post('emergency/{emergency}/triage', [EmergencyController::class, 'triage'])->name('emergency.triage');
            });
        });
    });
});
