<?php
use Illuminate\Support\Facades\Route;
use App\Modules\Core\HealthController;
use App\Modules\ERPIntegration\SyncController;
use App\Modules\ERPIntegration\ErpLocalSyncController;
use App\Modules\CRM\StaffAuthController;
use App\Modules\CRM\CrmController;
use App\Modules\CRM\CustomerMasterController;
use App\Modules\CRM\CustomerPurchaseController;
use App\Modules\CRM\FieldController;
use App\Modules\Member\MemberAuthController;
use App\Modules\Member\MemberController;
use App\Modules\Loyalty\LoyaltyController;
use App\Modules\LINE\LineWebhookController;

Route::get('/api/health', HealthController::class);

Route::prefix('api/sync/v1')->middleware(['sync.token', 'throttle:60,1'])->group(function () {
    Route::post('/customers', [SyncController::class, 'customers']);
    Route::post('/products', [SyncController::class, 'products']);
    Route::post('/invoices', [SyncController::class, 'invoices']);
    Route::post('/salespersons', [SyncController::class, 'salespersons']);
    Route::post('/members', [SyncController::class, 'members']);
});
Route::post('/api/line/webhook', LineWebhookController::class)->middleware('throttle:120,1');

Route::prefix('api/crm')->group(function () {
    Route::get('/csrf', [StaffAuthController::class, 'csrf']);
    Route::post('/login', [StaffAuthController::class, 'login'])->middleware('throttle:crm-login');
    Route::middleware('auth:staff')->group(function () {
        Route::post('/logout', [StaffAuthController::class, 'logout']);
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::get('/dashboard', [CrmController::class, 'dashboard']);
        Route::get('/salespersons', [CrmController::class, 'salespersons']);
        Route::get('/customers', [CrmController::class, 'customers']);
        Route::get('/customers/{id}', [CrmController::class, 'customerDetail'])->whereNumber('id');
        Route::get('/customers/{id}/invoices', [CustomerPurchaseController::class, 'invoices'])->whereNumber('id');
        Route::get('/customers/{id}/invoices/{invoiceId}', [CustomerPurchaseController::class, 'invoiceDetail'])->whereNumber(['id', 'invoiceId']);
        Route::get('/customers/{id}/purchase-insights', [CustomerPurchaseController::class, 'insights'])->whereNumber('id');
        Route::get('/visits', [CrmController::class, 'visits']);
        Route::post('/visits', [CrmController::class, 'storeVisit'])->middleware('throttle:30,1');

        Route::get('/reports/reconciliation', [CrmController::class, 'reconciliationReport']);
        Route::get('/reports/sales-dimensions', [CrmController::class, 'salesDimensions']);
        Route::get('/reports/customer-sales-matrix', [CrmController::class, 'customerSalesMatrix']);
        Route::get('/reports/product-size-segments', [CrmController::class, 'productSizeSegments']);
        Route::post('/reports/product-size-segments', [CrmController::class, 'saveProductSizeSegment']);
        Route::delete('/reports/product-size-segments/{segmentId}', [CrmController::class, 'deleteProductSizeSegment'])->whereNumber('segmentId');
        Route::get('/reports/field-visits', [FieldController::class, 'fieldVisitReport']);
        Route::get('/members', [CrmController::class, 'members']);
        Route::get('/member-links', [CrmController::class, 'memberLinks']);
        Route::post('/member-links', [CrmController::class, 'createMemberLink']);
        Route::post('/member-links/{id}/verify', [CrmController::class, 'verifyMemberLink']);
        Route::get('/settings/overview', [CrmController::class, 'settingsOverview']);

        Route::get('/leads', [FieldController::class, 'leads']);
        Route::post('/leads', [FieldController::class, 'storeLead'])->middleware('throttle:30,1');
        Route::post('/leads/quick', [FieldController::class, 'quickStoreLead'])->middleware('throttle:30,1');
        Route::post('/leads/check-duplicate', [FieldController::class, 'checkDuplicate'])->middleware('throttle:60,1');
        Route::get('/leads/import-template', [FieldController::class, 'importTemplate']);
        Route::post('/leads/import', [FieldController::class, 'importLeads'])->middleware('throttle:15,1');
        Route::get('/leads/{id}', [FieldController::class, 'leadDetail']);
        Route::patch('/leads/{id}', [FieldController::class, 'updateLead']);
        Route::patch('/leads/{id}/stage', [FieldController::class, 'updateLeadStage']);
        Route::post('/leads/{id}/review', [FieldController::class, 'reviewLead']);
        Route::get('/leads/{id}/photos', [FieldController::class, 'leadPhotos']);
        Route::post('/leads/{id}/photos', [FieldController::class, 'uploadLeadPhoto']);
        Route::get('/lead-photos/{photoId}', [FieldController::class, 'downloadLeadPhoto']);
        Route::post('/leads/{id}/tasks', [FieldController::class, 'addLeadTask']);
        Route::get('/customer-master', [CustomerMasterController::class, 'index']);
        Route::get('/customer-master/erp-creation-requests', [CustomerMasterController::class, 'erpCreationRequests']);
        Route::get('/customer-master/match-candidates', [CustomerMasterController::class, 'matchCandidates']);
        Route::post('/customer-master/match-candidates/refresh', [CustomerMasterController::class, 'refreshMatchCandidates']);
        Route::post('/customer-master/match-candidates/{candidateId}/confirm', [CustomerMasterController::class, 'confirmMatchCandidate'])->whereNumber('candidateId');
        Route::post('/customer-master/match-candidates/{candidateId}/dismiss', [CustomerMasterController::class, 'dismissMatchCandidate'])->whereNumber('candidateId');
        Route::post('/customer-master/leads/{leadId}/link-erp', [CustomerMasterController::class, 'linkLeadToErp'])->whereNumber('leadId');
        Route::post('/customer-master/leads/{leadId}/request-erp-creation', [CustomerMasterController::class, 'requestErpCreation'])->whereNumber('leadId');
        Route::get('/customer-master/{masterId}', [CustomerMasterController::class, 'show'])->whereNumber('masterId');
        Route::post('/customer-master/{masterId}/unlink-erp', [CustomerMasterController::class, 'unlinkErp'])->whereNumber('masterId');
        Route::get('/customer-master/{masterId}/audit', [CustomerMasterController::class, 'auditHistory'])->whereNumber('masterId');
        Route::get('/pins', [FieldController::class, 'pins']);
        Route::post('/pins', [FieldController::class, 'storePin'])->middleware('throttle:30,1');
        Route::get('/plans', [FieldController::class, 'plans']);
        Route::post('/plans', [FieldController::class, 'storePlan']);
        Route::post('/plans/{planId}/stops', [FieldController::class, 'addStop']);
        Route::post('/field-visits', [FieldController::class, 'storeFieldVisit']);
        Route::get('/field-visits/{visitId}/photos', [FieldController::class, 'visitPhotos']);
        Route::post('/field-visits/{visitId}/photos', [FieldController::class, 'uploadPhoto']);
        Route::get('/photos/{photoId}', [FieldController::class, 'downloadPhoto']);
        Route::get('/tasks', [FieldController::class, 'tasks']);
        Route::post('/tasks/{taskId}/complete', [FieldController::class, 'completeTask']);

        // Admin/Manager ERP Local Sync Monitor
        Route::prefix('sync-monitor')->group(function () {
            Route::get('/status', [ErpLocalSyncController::class, 'status']);
            Route::post('/test-connection', [ErpLocalSyncController::class, 'testConnection']);
            Route::post('/test-customers-10', [ErpLocalSyncController::class, 'testCustomers10']);
            Route::post('/test-products-10', [ErpLocalSyncController::class, 'testProducts10']);
            Route::post('/test-invoices-10', [ErpLocalSyncController::class, 'testInvoices10']);
            Route::post('/sync/{kind}', [ErpLocalSyncController::class, 'syncKind']);
        });

        // Phase C: Loyalty Rules, Member Verification Review, Transaction Reversal, Rewards Catalog
        Route::prefix('loyalty')->group(function () {
            Route::get('/rules', [LoyaltyController::class, 'rules']);
            Route::post('/rules', [LoyaltyController::class, 'storeRule']);
            Route::patch('/rules/{id}', [LoyaltyController::class, 'updateRule']);
            Route::get('/verifications', [LoyaltyController::class, 'verifications']);
            Route::post('/verifications/{id}/review', [LoyaltyController::class, 'reviewVerification']);
            Route::get('/ledger', [LoyaltyController::class, 'ledger']);
            Route::post('/ledger/reverse', [LoyaltyController::class, 'reverseLedger']);
            Route::get('/rewards', [LoyaltyController::class, 'rewards']);
            Route::post('/rewards', [LoyaltyController::class, 'storeReward']);
            Route::get('/redemptions', [LoyaltyController::class, 'redemptions']);
            Route::post('/redemptions/{id}/status', [LoyaltyController::class, 'updateRedemptionStatus']);
        });
    });
});

Route::prefix('api/member')->group(function () {
    Route::get('/csrf', [MemberAuthController::class, 'csrf']);
    Route::post('/register', [MemberAuthController::class, 'register'])->middleware('throttle:member-register');
    Route::post('/login', [MemberAuthController::class, 'login'])->middleware('throttle:member-login');
    Route::middleware('auth:member')->group(function () {
        Route::get('/me', [MemberAuthController::class, 'me']);
        Route::post('/logout', [MemberAuthController::class, 'logout']);
        Route::get('/profile', [MemberController::class, 'profile']);
        Route::patch('/profile', [MemberController::class, 'updateProfile']);
        Route::get('/invoices', [MemberController::class, 'invoices']);
        Route::get('/points', [MemberController::class, 'points']);
        Route::post('/line/link-token', [MemberController::class, 'createLineLinkToken'])->middleware('throttle:10,1');
        Route::delete('/line/binding', [MemberController::class, 'revokeLineBinding']);
        Route::post('/verification-request', [MemberController::class, 'submitVerificationRequest'])->middleware('throttle:10,1');
        Route::get('/rewards', [MemberController::class, 'rewards']);
        Route::post('/rewards/{rewardId}/redeem', [MemberController::class, 'redeemReward']);
        Route::get('/redemptions', [MemberController::class, 'redemptions']);
    });
});
