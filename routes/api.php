<?php

use App\Http\Controllers\Api\V1\Admin\PlatformController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashCloseController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Portal\PortalController;
use App\Http\Controllers\Api\V1\PublicPaymentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VisitController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 (§8). Tenant is always derived from the authenticated user
| (middleware "tenant"); every route is guarded by an explicit ability (§4).
*/

Route::prefix('v1')->group(function () {

    // ---- Public -----------------------------------------------------------
    Route::middleware('throttle:public')->group(function () {
        Route::get('plans', [AuthController::class, 'plans']);
        Route::get('portal/company/{slug}', [AuthController::class, 'portalCompany'])->where('slug', '[a-z0-9-]+');
        Route::get('pay/{token}', [PublicPaymentController::class, 'show']);
        Route::get('pay/{token}/pdf', [PublicPaymentController::class, 'pdf']);
        Route::post('pay/{token}/order', [PublicPaymentController::class, 'createOrder']);
        Route::post('pay/{token}/confirm', [PublicPaymentController::class, 'confirm']);
    });

    Route::post('payments/webhook/{driver}/{tenant?}', [PublicPaymentController::class, 'webhook'])
        ->middleware('throttle:120,1')->where(['driver' => '[a-z]+', 'tenant' => '[a-z0-9-]+']);

    Route::get('files/images/{image}', [VisitController::class, 'image'])->name('files.image')->middleware('signed');

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:login')->group(function () {
            Route::post('login', [AuthController::class, 'login']);
            Route::post('token', [AuthController::class, 'token']);
            Route::post('register', [AuthController::class, 'register']);
            Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('reset-password', [AuthController::class, 'resetPassword']);
        });
        Route::middleware('throttle:otp')->group(function () {
            Route::post('otp/request', [AuthController::class, 'otpRequest']);
            Route::post('otp/verify', [AuthController::class, 'otpVerify']);
        });
    });

    // ---- Authenticated ------------------------------------------------------
    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {

        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::put('password', [AuthController::class, 'changePassword'])->middleware('throttle:login');
            Route::post('two-factor', [AuthController::class, 'twoFactorSetup']);
            Route::post('two-factor/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:login');
            Route::delete('two-factor', [AuthController::class, 'twoFactorDisable']);
            Route::post('device', [AuthController::class, 'registerDevice']);
        });
        Route::post('impersonation/stop', [PlatformController::class, 'stopImpersonating']);

        // Super Admin (§5.1)
        Route::prefix('admin')->middleware('can:platform.manage')->group(function () {
            Route::get('metrics', [PlatformController::class, 'metrics']);
            Route::get('tenants', [PlatformController::class, 'tenants']);
            Route::post('tenants', [PlatformController::class, 'storeTenant']);
            Route::get('tenants/{id}', [PlatformController::class, 'showTenant'])->whereNumber('id');
            Route::patch('tenants/{id}', [PlatformController::class, 'updateTenant'])->whereNumber('id');
            Route::patch('tenants/{id}/status', [PlatformController::class, 'setTenantStatus'])->whereNumber('id');
            Route::delete('tenants/{id}', [PlatformController::class, 'destroyTenant'])->whereNumber('id');
            Route::post('tenants/{id}/impersonate', [PlatformController::class, 'impersonate'])->whereNumber('id');
            Route::get('plans', [PlatformController::class, 'plans']);
            Route::post('plans', [PlatformController::class, 'storePlan']);
            Route::patch('plans/{id}', [PlatformController::class, 'updatePlan'])->whereNumber('id');
            Route::get('audit-logs', [PlatformController::class, 'auditLogs']);
        });

        // Dashboards
        Route::get('dashboard', [DashboardController::class, 'tenant'])->middleware('can:dashboard.view');
        Route::get('dashboard/technician', [DashboardController::class, 'technician'])->middleware('can:visits.execute');

        // Users & attendance
        Route::middleware('can:users.view')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/{id}', [UserController::class, 'show'])->whereNumber('id');
            Route::get('punch-logs', [UserController::class, 'punchLogs']);
        });
        Route::get('users/options', [UserController::class, 'options'])->middleware('can:jobs.view');
        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::patch('users/{id}/status', [UserController::class, 'setStatus'])->whereNumber('id');
            Route::post('users/{id}/invite', [UserController::class, 'resendInvite'])->whereNumber('id');
        });
        Route::post('punch', [UserController::class, 'punch'])->middleware('can:punch');

        // Master data (§5.14)
        Route::get('master/lookups', [MasterDataController::class, 'lookups'])->middleware('can:master.view');
        Route::middleware('can:master.manage')->group(function () {
            Route::get('master/{type}', [MasterDataController::class, 'index']);
            Route::post('master/{type}', [MasterDataController::class, 'store']);
            Route::patch('master/{type}/{id}', [MasterDataController::class, 'update'])->whereNumber('id');
            Route::delete('master/{type}/{id}', [MasterDataController::class, 'destroy'])->whereNumber('id');
        });

        // Customers (§5.4)
        Route::middleware('can:customers.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('can:customers.manage')->group(function () {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::patch('customers/{id}', [CustomerController::class, 'update'])->whereNumber('id');
            Route::post('customers/{id}/products', [CustomerController::class, 'storeProduct'])->whereNumber('id');
            Route::patch('customers/{id}/products/{productId}', [CustomerController::class, 'updateProduct'])->whereNumber(['id', 'productId']);
        });
        Route::middleware('can:customers.privacy')->group(function () {
            Route::get('customers/{id}/export', [CustomerController::class, 'export'])->whereNumber('id');
            Route::delete('customers/{id}', [CustomerController::class, 'anonymize'])->whereNumber('id');
        });

        // Jobs (§5.5)
        Route::middleware('can:jobs.view')->group(function () {
            Route::get('jobs', [JobController::class, 'index']);
            Route::get('jobs/counts', [JobController::class, 'counts']);
            Route::get('jobs/calendar', [JobController::class, 'calendar']);
            Route::get('jobs/{id}', [JobController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('can:jobs.manage')->group(function () {
            Route::post('jobs', [JobController::class, 'store']);
            Route::patch('jobs/{id}', [JobController::class, 'update'])->whereNumber('id');
            Route::patch('jobs/{id}/assign', [JobController::class, 'assign'])->whereNumber('id');
            Route::patch('jobs/{id}/reschedule', [JobController::class, 'reschedule'])->whereNumber('id');
            Route::post('jobs/{id}/cancel', [JobController::class, 'cancel'])->whereNumber('id');
            Route::post('jobs/{id}/follow-up', [JobController::class, 'followUp'])->whereNumber('id');
        });

        // Service execution (§5.6) – technician only
        Route::middleware('can:visits.execute')->group(function () {
            Route::post('jobs/{id}/visits/start', [VisitController::class, 'start'])->whereNumber('id');
            Route::get('visits/active', [VisitController::class, 'active']);
            Route::get('visits/{id}', [VisitController::class, 'show'])->whereNumber('id');
            Route::patch('visits/{id}', [VisitController::class, 'update'])->whereNumber('id');
            Route::post('visits/{id}/images', [VisitController::class, 'uploadImage'])->whereNumber('id')->middleware('throttle:uploads');
            Route::delete('visits/{id}/images/{imageId}', [VisitController::class, 'deleteImage'])->whereNumber(['id', 'imageId']);
            Route::post('visits/{id}/spares', [VisitController::class, 'addSpare'])->whereNumber('id');
            Route::delete('visits/{id}/spares/{usageId}', [VisitController::class, 'removeSpare'])->whereNumber(['id', 'usageId']);
            Route::post('visits/{id}/complete', [VisitController::class, 'complete'])->whereNumber('id');
            Route::post('invoices/{id}/collect', [InvoiceController::class, 'collect'])->whereNumber('id');
        });

        // Inventory (§5.7)
        Route::middleware('can:inventory.view')->group(function () {
            Route::get('inventory/items', [InventoryController::class, 'items']);
            Route::get('inventory/categories', [InventoryController::class, 'categories']);
            Route::get('inventory/items/{item}/availability', [InventoryController::class, 'availability'])->where('item', '[A-Za-z0-9._\-\/]+');
            Route::get('inventory/stock', [InventoryController::class, 'stock']);
        });
        Route::middleware('can:inventory.manage')->group(function () {
            Route::post('inventory/items', [InventoryController::class, 'storeItem']);
            Route::patch('inventory/items/{id}', [InventoryController::class, 'updateItem'])->whereNumber('id');
            Route::post('inventory/stock-in', [InventoryController::class, 'stockIn']);
            Route::post('inventory/transfer', [InventoryController::class, 'transfer']);
            Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
            Route::get('inventory/transactions', [InventoryController::class, 'transactions']);
            Route::get('suppliers', [InventoryController::class, 'suppliers']);
            Route::post('suppliers', [InventoryController::class, 'storeSupplier']);
            Route::patch('suppliers/{id}', [InventoryController::class, 'updateSupplier'])->whereNumber('id');
        });

        // Invoices & payments (§5.10)
        Route::middleware('can:invoices.view')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
            Route::get('invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id');
        });
        Route::middleware('can:payments.record')->group(function () {
            Route::post('invoices/{id}/pay', [InvoiceController::class, 'recordPayment'])->whereNumber('id');
            Route::post('invoices/{id}/remind', [InvoiceController::class, 'sendReminder'])->whereNumber('id');
            Route::get('payments', [InvoiceController::class, 'payments']);
        });

        // Accounts – daily cash close (§5.15)
        Route::prefix('accounts')->group(function () {
            Route::middleware('can:cash.view')->group(function () {
                Route::get('cash-summary', [CashCloseController::class, 'summary']);
                Route::get('cash-close', [CashCloseController::class, 'index']);
                Route::get('cash-close/{id}', [CashCloseController::class, 'show'])->whereNumber('id');
                Route::get('ledger', [CashCloseController::class, 'ledger']);
            });
            Route::post('cash-close', [CashCloseController::class, 'submit'])->middleware('can:cash.submit');
            Route::middleware('can:cash.verify')->group(function () {
                Route::get('overview', [CashCloseController::class, 'overview']);
                Route::patch('cash-close/{id}/verify', [CashCloseController::class, 'verify'])->whereNumber('id');
                Route::post('cash-close/{id}/deposit', [CashCloseController::class, 'deposit'])->whereNumber('id');
            });
        });

        // Reports (§5.13)
        Route::middleware('can:reports.view')->group(function () {
            Route::get('reports', [ReportController::class, 'index']);
            Route::get('reports/exports', [ReportController::class, 'exports']);
            Route::get('reports/exports/{id}/download', [ReportController::class, 'download'])->whereNumber('id');
            Route::get('reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z-]+');
            Route::get('reports/{report}/export', [ReportController::class, 'export'])->where('report', '[a-z-]+')->middleware('throttle:exports');
        });

        // Notifications (§5.12)
        Route::get('notifications', [NotificationController::class, 'mine']);
        Route::post('notifications/read', [NotificationController::class, 'markRead']);
        Route::get('notifications/logs', [NotificationController::class, 'logs'])->middleware('can:notifications.logs');

        // Settings & audit
        Route::middleware('can:settings.manage')->prefix('settings')->group(function () {
            Route::get('/', [SettingsController::class, 'show']);
            Route::put('company', [SettingsController::class, 'updateCompany']);
            Route::put('preferences', [SettingsController::class, 'updateSettings']);
            Route::put('gateway', [SettingsController::class, 'updateGateway']);
            Route::post('logo', [SettingsController::class, 'uploadLogo']);
            Route::get('templates', [SettingsController::class, 'templates']);
            Route::put('templates', [SettingsController::class, 'saveTemplate']);
        });
        Route::get('settings/logo', [SettingsController::class, 'logo'])->middleware('can:dashboard.view');
        Route::get('audit-logs', [SettingsController::class, 'auditLogs'])->middleware('can:audit.view');

        // Customer portal (§5.11)
        Route::prefix('customer')->middleware('can:portal')->group(function () {
            Route::get('overview', [PortalController::class, 'overview']);
            Route::get('jobs', [PortalController::class, 'jobs']);
            Route::get('jobs/{id}', [PortalController::class, 'job'])->whereNumber('id');
            Route::post('complaints', [PortalController::class, 'raiseComplaint'])->middleware('throttle:10,1');
            Route::get('complaint-types', [PortalController::class, 'complaintTypes']);
            Route::get('invoices', [PortalController::class, 'invoices']);
            Route::get('invoices/{id}/pdf', [PortalController::class, 'invoicePdf'])->whereNumber('id');
            Route::post('invoices/{id}/pay', [PortalController::class, 'payInvoice'])->whereNumber('id');
            Route::post('invoices/{id}/confirm', [PortalController::class, 'confirmPayment'])->whereNumber('id');
            Route::post('jobs/{id}/review', [PortalController::class, 'review'])->whereNumber('id');
        });
    });
});
