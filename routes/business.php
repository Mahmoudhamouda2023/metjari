<?php

use App\Http\Controllers\Delivery\AddressController;
use App\Http\Controllers\Delivery\BenefitController;
use App\Http\Controllers\Delivery\CatalogController;
use App\Http\Controllers\Delivery\FinanceController;
use App\Http\Controllers\Delivery\LoyaltyController;
use App\Http\Controllers\Delivery\NotificationController;
use App\Http\Controllers\Delivery\OrderController;
use App\Http\Controllers\Delivery\PaymentController;
use App\Http\Controllers\Delivery\PricingStudyController;
use App\Http\Controllers\Delivery\ReportController;
use App\Http\Controllers\Delivery\ReturnController;
use App\Http\Controllers\Delivery\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/track/{token}', [OrderController::class, 'tracking'])->where('token', '[a-f0-9]{48}')->middleware('throttle:60,1')->name('tracking');
Route::prefix('merchant')->name('merchant.')->middleware(['auth:merchant', 'identity:merchant', 'password.changed:merchant', 'approved'])->group(function () {
    Route::get('points', [LoyaltyController::class, 'merchant'])->name('points');
    Route::post('points/redeem', [LoyaltyController::class, 'request'])->middleware('throttle:10,1')->name('points.redeem');
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:notification-feed')->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders/preview', [OrderController::class, 'preview'])->middleware('throttle:60,1')->name('orders.preview');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:60,1')->name('orders.store');
    Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}/status', [OrderController::class, 'transition'])->middleware('throttle:60,1')->name('orders.status');
    Route::post('orders/{order}/issues', [OrderController::class, 'issue'])->middleware('throttle:10,1')->name('orders.issue');
    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::patch('addresses/{address}', [AddressController::class, 'toggle'])->name('addresses.toggle');
    Route::get('benefits', [BenefitController::class, 'index'])->name('benefits.index');
    Route::get('received-payments', [FinanceController::class, 'received'])->name('received');
    Route::get('finance', [FinanceController::class, 'merchant'])->name('finance');
    Route::get('finance/{kind}/{id}', [FinanceController::class, 'receipt'])->where('kind', 'settlement')->name('finance.receipt');
});
Route::middleware(['auth:staff', 'identity:staff', 'password.changed:staff'])->group(function () {
    Route::prefix('captain')->name('captain.')->middleware('can:access-captain')->group(function () {
        Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:notification-feed')->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/service-fees/{entry}', [OrderController::class, 'collectServiceFee'])->whereNumber('entry')->middleware('throttle:service-fee-collection')->name('service-fees.collect');
        Route::patch('orders/{order}/status', [OrderController::class, 'transition'])->middleware('throttle:60,1')->name('orders.status');
        Route::patch('payments/{payment}', [PaymentController::class, 'correct'])->middleware('throttle:10,1')->name('payments.correct');
        Route::get('finance', [FinanceController::class, 'captain'])->name('finance');
        Route::post('finance/remittances', [FinanceController::class, 'requestRemittance'])->middleware('throttle:captain-remittance')->name('remittances.store');
    });
    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function () {
        Route::get('returns/export', [ReturnController::class, 'export'])->name('returns.export');
        Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::post('returns/{order}/assign', [ReturnController::class, 'assign'])->middleware(['can:operate-delivery', 'throttle:admin-write'])->name('returns.assign');
        Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:notification-feed')->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/assign', [OrderController::class, 'assign'])->middleware(['can:operate-delivery', 'throttle:admin-write'])->name('orders.assign');
        Route::patch('orders/{order}/status', [OrderController::class, 'transition'])->middleware(['can:operate-delivery', 'throttle:admin-write'])->name('orders.status');
        Route::get('issues', [OrderController::class, 'issues'])->middleware('can:operate-delivery')->name('issues');
        Route::patch('issues/{issue}', [OrderController::class, 'resolveIssue'])->middleware(['can:operate-delivery', 'throttle:admin-write'])->name('issues.resolve');
        Route::middleware('can:configure-delivery')->group(function () {
            Route::get('points/settings', [LoyaltyController::class, 'settings'])->name('points.settings');
            Route::put('points/settings', [LoyaltyController::class, 'updateSettings'])->middleware('throttle:admin-write')->name('points.settings.update');
            Route::get('rewards', [LoyaltyController::class, 'admin'])->name('rewards.index');
            Route::get('points/stores/{storeId}', [LoyaltyController::class, 'account'])->whereNumber('storeId')->name('points.account');
            Route::patch('rewards/{rewardRequest}', [LoyaltyController::class, 'review'])->middleware('throttle:admin-write')->name('rewards.review');
            Route::get('catalog/{kind}', [CatalogController::class, 'index'])->whereIn('kind', ['zones', 'neighborhoods', 'tariffs', 'agreements', 'coupons'])->name('catalog.index');
            Route::get('catalog/{kind}/{id}/edit', [CatalogController::class, 'edit'])->whereIn('kind', ['zones', 'neighborhoods', 'tariffs', 'agreements', 'coupons'])->name('catalog.edit');
            Route::post('catalog/{kind}', [CatalogController::class, 'store'])->middleware('throttle:admin-write')->whereIn('kind', ['zones', 'neighborhoods', 'tariffs', 'agreements', 'coupons'])->name('catalog.store');
            Route::patch('catalog/{kind}/{id}', [CatalogController::class, 'update'])->middleware('throttle:admin-write')->whereIn('kind', ['zones', 'neighborhoods', 'tariffs', 'agreements', 'coupons'])->name('catalog.update');
            Route::get('pricing-study', PricingStudyController::class)->name('pricing-study');
            Route::get('subscriptions', [BenefitController::class, 'admin'])->name('subscriptions');
            Route::post('gifts', [BenefitController::class, 'gift'])->middleware('throttle:admin-write')->name('gifts.store');
        });
        Route::middleware('can:finance-delivery')->group(function () {
            Route::get('orders/{order}/payment/edit', [PaymentController::class, 'edit'])->name('orders.payment.edit');
            Route::patch('orders/{order}/payment', [PaymentController::class, 'correctOrder'])->middleware('throttle:admin-write')->name('orders.payment.correct');
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::patch('payments/{payment}', [PaymentController::class, 'review'])->middleware('throttle:admin-write')->name('payments.review');
            Route::post('finance/receipts/{kind}/{id}/reverse', [FinanceController::class, 'reverse'])->whereIn('kind', ['settlement', 'captain', 'remittance'])->middleware('throttle:admin-write')->name('finance.reverse');
            Route::get('finance', [FinanceController::class, 'index'])->name('finance');
            Route::get('finance/stores', [FinanceController::class, 'index'])->name('finance.stores');
            Route::get('finance/captains', [FinanceController::class, 'index'])->name('finance.captains');
            Route::get('finance/remittances', [FinanceController::class, 'index'])->name('finance.remittances');
            Route::get('finance/live', [FinanceController::class, 'index'])->name('finance.live');
            Route::get('finance/history', [FinanceController::class, 'history'])->name('finance.history');
            Route::patch('finance/remittances/{remittance}', [FinanceController::class, 'remittance'])->middleware('throttle:admin-write')->name('finance.remittance');
            Route::post('finance/settlements/{store}', [FinanceController::class, 'settle'])->middleware('throttle:admin-write')->name('finance.settle');
            Route::post('finance/payouts/{captain}', [FinanceController::class, 'payout'])->middleware('throttle:admin-write')->name('finance.payout');
            Route::post('finance/expenses', [FinanceController::class, 'expense'])->middleware('throttle:admin-write')->name('finance.expense');
            Route::get('finance/receipts/{kind}/{id}', [FinanceController::class, 'receipt'])->whereIn('kind', ['settlement', 'captain', 'remittance'])->name('finance.receipt');
        });
        Route::get('audit', [CatalogController::class, 'audit'])->middleware('can:manage-staff')->name('audit');
    });
});
Route::prefix('admin')->name('admin.')->middleware(['auth:staff', 'identity:staff', 'password.changed:staff', 'can:manage-staff'])->group(function () {
    Route::get('team/{staff}/edit', [TeamController::class, 'edit'])->name('team.edit');
    Route::patch('team/{staff}', [TeamController::class, 'update'])->middleware('throttle:admin-write')->name('team.update');
    Route::post('team/{staff}/reset-password', [TeamController::class, 'resetStaff'])->middleware('throttle:admin-write')->name('team.reset');
    Route::post('stores/{store}/reset-password', [TeamController::class, 'resetMerchant'])->middleware('throttle:admin-write')->name('stores.reset');
});
Route::get('admin/reports', ReportController::class)->middleware(['auth:staff', 'identity:staff', 'password.changed:staff', 'can:finance-delivery'])->name('admin.reports');
