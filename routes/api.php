<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountRecoveryController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DealFinanceController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadImportController;
use App\Http\Controllers\Api\InvitationAcceptanceController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PreferredLocationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\PlatformPaymentSettingsController;
use App\Http\Controllers\Api\PropertyViewingController;
use App\Http\Controllers\Api\RealEstateDeveloperController;
use App\Http\Controllers\Api\CompanyBrandingController;
use App\Http\Controllers\Api\DataExportController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InstallmentController;
use App\Http\Controllers\Api\BranchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('v1')->middleware('web')->group(function () {
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
    Route::post('/subscriptions/paymob/callback', [SubscriptionController::class, 'paymobCallback'])->middleware('throttle:30,1')->name('subscriptions.paymob-callback');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/invitations/accept', InvitationAcceptanceController::class)->middleware('throttle:10,1');
    Route::post('/auth/password/forgot', [AccountRecoveryController::class, 'sendResetLink'])->middleware('throttle:3,1');
    Route::post('/auth/password/reset', [AccountRecoveryController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/auth/email/verification-notification', [AccountRecoveryController::class, 'sendVerificationLink'])->middleware('throttle:3,1');
    Route::get('/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
        ->middleware('signed')->name('auth.email.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware(['verified', 'platform-admin'])->prefix('platform')->group(function () {
            Route::get('/bootstrap', [PlatformAdminController::class, 'bootstrap']);
            Route::get('/overview', [PlatformAdminController::class, 'overview']);
            Route::get('/paymob-settings', [PlatformPaymentSettingsController::class, 'show']);
            Route::put('/paymob-settings', [PlatformPaymentSettingsController::class, 'update']);
            Route::get('/plans', [PlatformAdminController::class, 'plans']);
            Route::put('/plans/{plan}', [PlatformAdminController::class, 'updatePlan']);
            Route::get('/companies', [PlatformAdminController::class, 'companies']);
            Route::post('/companies/{company}/suspend', [PlatformAdminController::class, 'suspend']);
            Route::post('/companies/{company}/activate', [PlatformAdminController::class, 'activate']);
            Route::post('/companies/{company}/renew', [PlatformAdminController::class, 'renew']);
        });
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/companies/{company}/switch', [BootstrapController::class, 'switchCompany']);

        Route::middleware('verified')->group(function () {
          Route::middleware(['tenant', 'branch'])->group(function () {
            Route::get('/bootstrap', BootstrapController::class);
            Route::get('/company/logo', [CompanyBrandingController::class, 'show'])->name('company.logo');
            Route::get('/subscriptions', [SubscriptionController::class, 'status']);
            Route::post('/subscriptions/free-trial', [SubscriptionController::class, 'startFreeTrial']);
            Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:5,1');
            Route::middleware('subscription')->group(function () {
            Route::get('/dashboard', DashboardController::class);
            Route::get('/branches', [BranchController::class, 'index']);
            Route::post('/branches', [BranchController::class, 'store']);
            Route::put('/branches/{branch}', [BranchController::class, 'update']);
            Route::put('/branches/{branch}/members', [BranchController::class, 'members']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
            Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'payment']);
            Route::get('/installments', [InstallmentController::class, 'index']);
            Route::post('/deals/{deal}/installments/generate', [InstallmentController::class, 'generate']);
            Route::post('/installments/{installment}/payments', [InstallmentController::class, 'pay']);
            Route::post('/company/logo', [CompanyBrandingController::class, 'store']);
            Route::delete('/company/logo', [CompanyBrandingController::class, 'destroy']);
            Route::get('/whatsapp/settings', [WhatsAppController::class, 'settings']);
            Route::put('/whatsapp/accounts/{scope}', [WhatsAppController::class, 'saveAccount']);
            Route::delete('/whatsapp/accounts/{scope}', [WhatsAppController::class, 'disconnectAccount']);
            Route::patch('/whatsapp/templates/{template}', [WhatsAppController::class, 'updateTemplate']);
            Route::get('/exports/leads', [DataExportController::class, 'leads']);
            Route::get('/exports/inventory', [DataExportController::class, 'inventory']);
            Route::get('/exports/developers', [DataExportController::class, 'developers']);
            Route::get('/exports/deals', [DataExportController::class, 'deals']);
            Route::get('/exports/commissions', [DataExportController::class, 'commissions']);
            Route::get('/exports/tasks', [DataExportController::class, 'tasks']);
            Route::get('/exports/team', [DataExportController::class, 'team']);
            Route::get('/developers', [RealEstateDeveloperController::class, 'index']);
            Route::post('/developers', [RealEstateDeveloperController::class, 'store']);
            Route::put('/developers/{developer}', [RealEstateDeveloperController::class, 'update']);
            Route::delete('/developers/{developer}', [RealEstateDeveloperController::class, 'destroy']);
            Route::get('/inventory', [InventoryController::class, 'index']);
            Route::post('/inventory', [InventoryController::class, 'store']);
            Route::get('/inventory/{listing}', [InventoryController::class, 'show']);
            Route::put('/inventory/{listing}', [InventoryController::class, 'update']);
            Route::post('/inventory/{listing}/photos', [InventoryController::class, 'uploadPhoto']);
            Route::get('/inventory/{listing}/photos/{photo}', [InventoryController::class, 'showPhoto'])->name('inventory.photos.show');
            Route::delete('/inventory/{listing}/photos/{photo}', [InventoryController::class, 'deletePhoto']);
            Route::get('/deals', [DealController::class, 'index']);
            Route::post('/deals', [DealController::class, 'store']);
            Route::get('/deals/{deal}', [DealController::class, 'show']);
            Route::put('/deals/{deal}', [DealController::class, 'update']);
            Route::get('/deals/{deal}/commissions', [DealFinanceController::class, 'commissions']);
            Route::post('/deals/{deal}/commissions', [DealFinanceController::class, 'createCommission']);
            Route::patch('/deals/{deal}/commissions/{commission}', [DealFinanceController::class, 'updateCommission']);
            Route::get('/deals/{deal}/documents', [DealFinanceController::class, 'documents']);
            Route::post('/deals/{deal}/documents', [DealFinanceController::class, 'uploadDocument']);
            Route::get('/deals/{deal}/documents/{document}', [DealFinanceController::class, 'downloadDocument'])->name('deals.documents.download');
            Route::get('/reports', ReportController::class);
            Route::get('/commissions', [CommissionController::class, 'index']);
            Route::get('/commissions/{commission}', [CommissionController::class, 'show']);
            Route::post('/commissions/{commission}/signed-document', [CommissionController::class, 'uploadSignedDocument']);
            Route::get('/commissions/{commission}/signed-document', [CommissionController::class, 'downloadSignedDocument'])->name('commissions.signed-document');
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::post('/leads/bulk', [LeadController::class, 'bulk']);
            Route::get('/leads/import-template', [LeadImportController::class, 'template']);
            Route::post('/leads/import/preview', [LeadImportController::class, 'preview']);
            Route::post('/leads/import', [LeadImportController::class, 'import']);
            Route::get('/leads/{lead}', [LeadController::class, 'show']);
            Route::get('/leads/{lead}/matches', [LeadController::class, 'matches']);
            Route::post('/leads/{lead}/viewings', [PropertyViewingController::class, 'store']);
            Route::patch('/leads/{lead}/viewings/{viewing}', [PropertyViewingController::class, 'update']);
            Route::put('/leads/{lead}', [LeadController::class, 'update']);
            Route::delete('/leads/{lead}', [LeadController::class, 'archive']);
            Route::post('/leads/{lead}/restore', [LeadController::class, 'restore']);
            Route::post('/leads/{lead}/activities', [LeadController::class, 'addActivity']);
            Route::get('/leads/{lead}/whatsapp/options', [WhatsAppController::class, 'options']);
            Route::get('/leads/{lead}/whatsapp/messages', [WhatsAppController::class, 'history']);
            Route::post('/leads/{lead}/whatsapp/messages', [WhatsAppController::class, 'send']);
            Route::get('/preferred-locations', [PreferredLocationController::class, 'index']);
            Route::post('/preferred-locations', [PreferredLocationController::class, 'store']);
            Route::put('/preferred-locations/{preferredLocation}', [PreferredLocationController::class, 'update']);
            Route::delete('/preferred-locations/{preferredLocation}', [PreferredLocationController::class, 'destroy']);
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete']);
            Route::get('/team/members', [TeamController::class, 'index']);
            Route::post('/team/members', [TeamController::class, 'store']);
            Route::patch('/team/members/{membership}', [TeamController::class, 'update']);
            Route::delete('/team/invitations/{invitation}', [TeamController::class, 'revoke']);
            });
          });
        });
    });
});
