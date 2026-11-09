<?php

use App\Http\Controllers\Admin\SallaAppInstallationController;
use App\Http\Controllers\Zid\ZidAuthController;
use App\Http\Controllers\Zid\ZidSubscriptionController;
use App\Models\Language;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\ShopifyIntegratin\ShopifyAuthController;
use App\Http\Controllers\ShopifyIntegratin\ShopifyWebhookController;
use App\Http\Controllers\ShopifyIntegratin\PrivacyWebhookController;
use App\Http\Controllers\User\WooCommerceAutomationController;
use App\Http\Controllers\User\WooCommerceIntegrationController;
use App\Http\Controllers\WooCommerce\WooCommerceWebhookController;
use App\Http\Controllers\Zid\ZidWebhookController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/



Route::get('/new-install', [ShopifyAuthController::class, 'install']);
Route::get('/shopify/callback', [ShopifyAuthController::class, 'callback']);
Route::get('/shopify/install/success', [ShopifyAuthController::class, 'installSuccess'])->name('shopify.install.success');
Route::post('/shopify/webhooks/order-created', [ShopifyWebhookController::class, 'handleOrderCreated']);
Route::post('/shopify/webhooks/orders-updated', [ShopifyWebhookController::class, 'handleOrderUpdated']);
Route::post('/shopify/webhooks/orders-fulfilled', [ShopifyWebhookController::class, 'handleOrderFulfilled']);
Route::post('/shopify/webhooks/checkouts-created', [ShopifyWebhookController::class, 'handleCheckoutsCreated']);
Route::post('/shopify/webhooks/customers/data_request', [PrivacyWebhookController::class, 'handle']);
Route::post('/shopify/webhooks/customers/redact', [PrivacyWebhookController::class, 'handle']);
Route::post('/shopify/webhooks/shop/redact', [PrivacyWebhookController::class, 'handle']);

Route::post('/woocommerce/webhooks', [WooCommerceWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

    Route::post('/webhooks/status-change', [WooCommerceWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

Route::post('zid/webhooks', [ZidWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);


Route::middleware(['web'])->group(function () {
    Route::get('/zid/auth/callback', [ZidAuthController::class, 'handleCallback'])->name('zid.callback');
});

Route::post('/webhook/{organization}', [App\Http\Controllers\WebhookController::class, 'handlePostRequest'])
    ->whereNumber('organization');
Route::get('/widget/{organization}', [App\Http\Controllers\WebWidgetController::class, 'showPublicConfig']);
Route::get('/widget.js', [App\Http\Controllers\WebWidgetController::class, 'script']);

//Installer routes
Route::get('/install/{step?}', [App\Http\Controllers\InstallerController::class, 'index'])->name('install');
Route::post('/install/configure-database', [App\Http\Controllers\InstallerController::class, 'configureDatabase']);
Route::post('/install/configure-company', [App\Http\Controllers\InstallerController::class, 'configureCompany']);
Route::post('/install/migrate', [App\Http\Controllers\InstallerController::class, 'runMigrations']);
Route::get('/update', [App\Http\Controllers\InstallerController::class, 'update'])->name('install.update');
Route::post('/update', [App\Http\Controllers\InstallerController::class, 'runUpdate']);

Route::get('/current-locale', function () {
    return response()->json(['locale' => app()->getLocale()]);
});

Route::get('/locales', function () {
    $locales = Cache::remember(
        'locales:active-codes',
        now()->addMinutes(10),
        fn () => Language::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('code')
    );

    return response()->json($locales);
})->withoutMiddleware([
    \App\Http\Middleware\HandleInertiaRequests::class,
    \App\Http\Middleware\Localization::class,
]);

Route::get('/translations/{locale}', function ($locale) {
    $path = base_path("lang/{$locale}.json");
    if (File::exists($path)) {
        $translations = File::get($path);

        return response()->json(json_decode($translations, true));
    } else {
        return response()->json(['error' => 'Locale not found'], 404);
    }
});

//Frontend Routes
Route::match(['get', 'post'], '/', [App\Http\Controllers\FrontendController::class, 'index']);
Route::match(['get', 'post'], '/pages/{slug}', [App\Http\Controllers\FrontendController::class, 'pages']);
Route::match(['get', 'post'], '/privacy', [App\Http\Controllers\FrontendController::class, 'privacy']);
Route::match(['get', 'post'], '/terms-of-service', [App\Http\Controllers\FrontendController::class, 'termsOfService']);
Route::match(['get', 'post'], '/process-campaign', [App\Http\Controllers\FrontendController::class, 'buildTemplateChatMessage']);
Route::get('/language/{locale}', [App\Http\Controllers\FrontendController::class, 'changeLanguage']);

//File Route
Route::get('media/{filename}', [App\Http\Controllers\FileController::class, 'show'])->where('filename', '.*');

//Invite Routes
Route::get('/invite/{identifier}', [App\Http\Controllers\AuthController::class, 'viewInvite']);
Route::post('/invite/{identifier}', [App\Http\Controllers\AuthController::class, 'invite']);

Route::get('/logout', [App\Http\Controllers\AuthController::class, 'logout'])->name('logout');

//Webhook
Route::match(['get', 'post'], '/webhook/whatsapp/{identifier?}', [App\Http\Controllers\WebhookController::class, 'handle']);
Route::match(['get', 'post'], '/webhook/waba', [App\Http\Controllers\WebhookController::class, 'whatsappWebhook']);
Route::match(['get', 'post'], '/webhook/waba/new', [App\Http\Controllers\WebhookController::class, 'whatsappWebhook']);

// Salla webhooks
Route::post('/webhook/salla', [App\Http\Controllers\Salla\SallaWebhookController::class, 'handle']);

Route::match(['get', 'post'], '/webhook/{processor}', [App\Http\Controllers\WebhookController::class, 'processWebhook']);
Route::match(['get', 'post'], '/payment/{processor}', [App\Http\Controllers\PaymentController::class, 'processPayment']);

Route::get('/campaign-send', [App\Http\Controllers\FrontendController::class, 'sendCampaign']);
Route::get('/migrate-upgrade', [App\Http\Controllers\FrontendController::class, 'migrate']);

Route::middleware(['guest', 'redirectIfAuthenticated:user,admin'])->group(function () {
    Route::get('/login', [App\Http\Controllers\AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [App\Http\Controllers\AuthController::class, 'login'])->name('login');
    Route::get('/tfa', [App\Http\Controllers\AuthController::class, 'showTfaForm']);
    Route::post('/tfa', [App\Http\Controllers\AuthController::class, 'tfaVerify']);
    Route::get('/social-login/{type?}', [App\Http\Controllers\AuthController::class, 'socialLogin']);
    Route::get('/google/callback', [App\Http\Controllers\AuthController::class, 'googleCallback'])->name('google.callback');
    Route::get('/facebook/callback', [App\Http\Controllers\AuthController::class, 'handleFacebookCallback']);
    Route::get('/signup', [App\Http\Controllers\AuthController::class, 'showRegistrationForm']);
    Route::post('/signup', [App\Http\Controllers\AuthController::class, 'handleRegistration']);
    Route::get('/forgot-password', [App\Http\Controllers\AuthController::class, 'showForgotForm']);
    Route::post('/forgot-password', [App\Http\Controllers\AuthController::class, 'createPasswordResetToken']);
    Route::get('/reset-password', [App\Http\Controllers\AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [App\Http\Controllers\AuthController::class, 'resetPassword']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/VerifyAccount', [App\Http\Controllers\AuthController::class, 'VerifyAccount'])
        ->name('VerifyAccount');

    Route::post('/verify-account-code', [App\Http\Controllers\AuthController::class, 'verifyAccountCode'])
        ->name('VerifyAccountCode');

    Route::get('/otp-email', [App\Http\Controllers\AuthController::class, 'showOtpEmailForm']);

    Route::post('/send-verification-code', [App\Http\Controllers\AuthController::class, 'sendVerificationCodeToEmail']);

    Route::post('/resend-verification-code', [App\Http\Controllers\AuthController::class, 'resendVerificationCode']);
});

Route::middleware(['auth:user,admin'])->group(function () {
    Route::put('/profile', [App\Http\Controllers\ProfileController::class, 'update']);
    Route::put('/profile/password', [App\Http\Controllers\ProfileController::class, 'updatePassword']);
    Route::put('/profile/tfa', [App\Http\Controllers\ProfileController::class, 'updateTfa']);
    Route::put('/profile/organization', [App\Http\Controllers\ProfileController::class, 'updateOrganization']);
});

Route::middleware(['auth:user'])->group(function () {
    Route::get('/email/verify', [App\Http\Controllers\AuthController::class, 'verifyEmail'])->middleware('auth')->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect('/dashboard');
    })->middleware(['auth', 'signed'])->name('verification.verify');

    Route::post('/email/verification-notification', [App\Http\Controllers\AuthController::class, 'sendEmailVerification'])->middleware(['auth', 'throttle:6,1'])->name('verification.send');

    Route::group(['middleware' => ['check.email.verification']], function () {
        Route::get('/select-organization', [App\Http\Controllers\User\OrganizationController::class, 'index'])->name('user.organization.index');
        Route::post('/select-organization', [App\Http\Controllers\User\OrganizationController::class, 'selectOrganization'])->name('user.organization.selectOrganization');
        Route::post('/organization', [App\Http\Controllers\User\OrganizationController::class, 'store'])->name('user.organization.store');

        Route::group(['middleware' => ['check.organization']], function () {
            Route::get('/account/suspended', [App\Http\Controllers\User\AccountStatusController::class, 'suspended'])
                ->name('user.account.suspended');
            Route::post('/subscription/cancellation-request', [App\Http\Controllers\User\SubscriptionCancellationRequestController::class, 'store'])
                ->middleware('throttle:3,60')
                ->name('user.subscription.cancellation-request');
        });

        Route::group(['middleware' => ['check.organization', 'isTrial']], function () {
            //User Panel Routes
            Route::match(['get', 'post'], '/dashboard', [App\Http\Controllers\User\DashboardController::class, 'index'])->name('dashboard');
            Route::post('/dashboard/pre-setup-state', [App\Http\Controllers\User\DashboardController::class, 'updatePreSetupState'])->name('dashboard.pre-setup-state');

            Route::group(['middleware' => 'check.client.role'], function () {
                Route::delete('dismiss-notification/{type}', [App\Http\Controllers\User\DashboardController::class, 'dismissNotification'])->name('dashboard.team.notification.dismiss');
                Route::match(['get', 'post'], '/billing', [App\Http\Controllers\User\BillingController::class, 'index'])->name('user.billing.index');
                Route::post('/pay', [App\Http\Controllers\User\BillingController::class, 'pay'])->name('user.billing.pay');
                Route::resource('subscription', App\Http\Controllers\User\SubscriptionController::class);
                Route::post('/subscription/coupon/apply/{id}', [App\Http\Controllers\User\SubscriptionController::class, 'applyCoupon']);
                Route::get('/subscription/coupon/remove/{id}', [App\Http\Controllers\User\SubscriptionController::class, 'removeCoupon']);
            });

            Route::get('/zid/connect', [ZidAuthController::class, 'redirectToProvider'])->name('zid.connect');



            Route::group(['middleware' => ['check.organization', 'isTrial']], function () {
                Route::get('/chats/{uuid?}', [App\Http\Controllers\User\ChatController::class, 'index']);
                Route::get('/chats/{id}/media', [App\Http\Controllers\User\ChatController::class, 'getMedia']);
                Route::post('/chats', [App\Http\Controllers\User\ChatController::class, 'sendMessage']);
                 Route::post('/chats/send-template-to-number', [App\Http\Controllers\User\ChatController::class, 'sendTemplateToPhone']);
                Route::delete('/chats/{uuid}', [App\Http\Controllers\User\ChatController::class, 'deleteChats']);
                Route::get('/chat/send', [App\Http\Controllers\User\ChatController::class, 'sendMessage']);
                Route::post('/chat/{uuid}/send/template', [App\Http\Controllers\User\ChatController::class, 'sendTemplateMessage']);
                Route::post('/chat/{uuid}/send/carousel-template', [App\Http\Controllers\User\ChatController::class, 'sendCarouselTemplateMessage']);
                Route::post('/chat/{uuid}/send/meta-flow', [App\Http\Controllers\User\ChatController::class, 'sendMetaFlowMessage']);
                Route::post('/chats/update-sort-direction', [App\Http\Controllers\User\ChatController::class, 'updateChatSortDirection']);
                Route::get('/chats/{contactId}/messages', [App\Http\Controllers\User\ChatController::class, 'loadMoreMessages']);
                Route::get('/chats/{contactUuid}/search', [App\Http\Controllers\User\ChatController::class, 'searchMessages'])->middleware('throttle:120,1');
                Route::get('/chats/{contactUuid}/messages/around/{logId}', [App\Http\Controllers\User\ChatController::class, 'messagesAround'])->middleware('throttle:120,1');
                Route::get('/chats/{contactUuid}/messages/around-reply', [App\Http\Controllers\User\ChatController::class, 'messagesAroundReply'])->middleware('throttle:120,1');
                Route::get('/chats/labels/filter-data', [App\Http\Controllers\User\ChatController::class, 'getLabelsForFilter']);
                Route::get('/chats/team-members/filter-data', [App\Http\Controllers\User\ChatController::class, 'getTeamMembersForFilter']);
                Route::post('/chats/mark-all-read', [App\Http\Controllers\User\ChatController::class, 'markAllAsRead']);
                Route::post('/chats/{contactUuid}/mark-latest-unread', [App\Http\Controllers\User\ChatController::class, 'markLatestInboundAsUnread']);

                // Salla Integration Routes
                Route::get('/settings/salla', [App\Http\Controllers\User\SallaController::class, 'index'])->name('salla.index');
                Route::post('/settings/salla/{id}/refresh', [App\Http\Controllers\User\SallaController::class, 'refreshToken'])->name('salla.refresh');
                Route::delete('/settings/salla/{id}', [App\Http\Controllers\User\SallaController::class, 'disconnect'])->name('salla.disconnect');
                Route::get('/settings/salla/automations', [App\Http\Controllers\User\SallaAutomationController::class, 'index'])->name('salla.automations.index');
                Route::get('/settings/salla/automations/template/{templateId}/variables', [App\Http\Controllers\User\SallaAutomationController::class, 'templateVariables'])->whereNumber('templateId');
                Route::get('/settings/salla/automations/order-created/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFields'])->name('salla.automations.order_created.fields');
                Route::post('/settings/salla/automations/order-created', [App\Http\Controllers\User\SallaAutomationController::class, 'updateOrderCreated'])->name('salla.automations.order_created.update');
                Route::post('/settings/salla/automations/order-created-cod', [App\Http\Controllers\User\SallaAutomationController::class, 'updateOrderCreatedCod'])->name('salla.automations.order_created_cod.update');
                Route::get('/settings/salla/automations/order-status-updated/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFieldsOrderStatusUpdated'])->name('salla.automations.order_status_updated.fields');
                Route::post('/settings/salla/automations/order-status-updated', [App\Http\Controllers\User\SallaAutomationController::class, 'updateOrderStatusUpdated'])->name('salla.automations.order_status_updated.update');
                Route::get('/settings/salla/automations/abandoned-cart/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFieldsAbandonedCart'])->name('salla.automations.abandoned_cart.fields');
                Route::post('/settings/salla/automations/abandoned-cart', [App\Http\Controllers\User\SallaAutomationController::class, 'updateAbandonedCart'])->name('salla.automations.abandoned_cart.update');
                Route::get('/settings/salla/automations/customer-login/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFieldsCustomerLogin'])->name('salla.automations.customer_login.fields');
                Route::post('/settings/salla/automations/customer-login', [App\Http\Controllers\User\SallaAutomationController::class, 'updateCustomerLogin'])->name('salla.automations.customer_login.update');
                Route::get('/settings/salla/automations/customer-otp/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFieldsCustomerOtp'])->name('salla.automations.customer_otp.fields');
                Route::post('/settings/salla/automations/customer-otp', [App\Http\Controllers\User\SallaAutomationController::class, 'updateCustomerOtp'])->name('salla.automations.customer_otp.update');
                Route::get('/settings/salla/automations/communication-whatsapp-send/webhook-fields', [App\Http\Controllers\User\SallaAutomationController::class, 'webhookFieldsCommunicationWhatsappSend'])->name('salla.automations.communication_whatsapp_send.fields');
                Route::post('/settings/salla/automations/communication-whatsapp-send', [App\Http\Controllers\User\SallaAutomationController::class, 'updateCommunicationWhatsappSend'])->name('salla.automations.communication_whatsapp_send.update');
                Route::get('/settings/salla/message-logs', [App\Http\Controllers\User\SallaMessageLogController::class, 'index'])->name('salla.logs.index');
                Route::get('/settings/zid/message-logs', [App\Http\Controllers\User\ZidMessageLogController::class, 'index'])->name('zid.logs.index');
                Route::get('/settings/zid/automations', [App\Http\Controllers\User\ZidAutomationController::class, 'index'])->name('zid.automations.index');
                Route::get('/settings/zid/automations/template/{templateId}/variables', [App\Http\Controllers\User\ZidAutomationController::class, 'templateVariables'])->whereNumber('templateId');
                Route::get('/settings/zid/automations/customer-login/webhook-fields', [App\Http\Controllers\User\ZidAutomationController::class, 'webhookFieldsCustomerLogin'])->name('zid.automations.customer_login.fields');
                Route::post('/settings/zid/automations/customer-login', [App\Http\Controllers\User\ZidAutomationController::class, 'updateCustomerLogin'])->name('zid.automations.customer_login.update');
                Route::get('/settings/zid/automations/order-status-updated/webhook-fields', [App\Http\Controllers\User\ZidAutomationController::class, 'webhookFieldsOrderStatusUpdated'])->name('zid.automations.order_status.fields');
                Route::post('/settings/zid/automations/order-status-updated', [App\Http\Controllers\User\ZidAutomationController::class, 'updateOrderStatusUpdated'])->name('zid.automations.order_status.update');
                Route::get('/settings/zid/automations/order-created/webhook-fields', [App\Http\Controllers\User\ZidAutomationController::class, 'webhookFieldsOrderCreated'])->name('zid.automations.order_created.fields');
                Route::post('/settings/zid/automations/order-created', [App\Http\Controllers\User\ZidAutomationController::class, 'updateOrderCreated'])->name('zid.automations.order_created.update');
                Route::get('/settings/zid/automations/abandoned-cart/webhook-fields', [App\Http\Controllers\User\ZidAutomationController::class, 'webhookFieldsAbandonedCart'])->name('zid.automations.abandoned_cart.fields');
                Route::post('/settings/zid/automations/abandoned-cart', [App\Http\Controllers\User\ZidAutomationController::class, 'updateAbandonedCart'])->name('zid.automations.abandoned_cart.update');

                Route::get('/zid/connect', [ZidAuthController::class, 'redirectToProvider'])->name('zid.connect');
                Route::get('/zid/callback', [ZidAuthController::class, 'handleCallback'])->name('zid.callback');
                Route::post('/settings/zid/subscriptions', [ZidSubscriptionController::class, 'update'])->name('zid.subscriptions.update');
                Route::delete('/settings/zid/connection', [ZidAuthController::class, 'disconnect'])->name('zid.disconnect');

                Route::get('/settings/shopify/automations', [App\Http\Controllers\User\ShopifyAutomationController::class, 'index'])->name('shopify.automations.index');
                Route::get('/settings/shopify/automations/template/{templateId}/variables', [App\Http\Controllers\User\ShopifyAutomationController::class, 'templateVariables'])->whereNumber('templateId');
                Route::get('/settings/shopify/automations/order-created/webhook-fields', [App\Http\Controllers\User\ShopifyAutomationController::class, 'webhookFields'])->name('shopify.automations.order_created.fields');
                Route::post('/settings/shopify/automations/order-created', [App\Http\Controllers\User\ShopifyAutomationController::class, 'updateOrderCreated'])->name('shopify.automations.order_created.update');
                Route::get('/settings/shopify/automations/checkouts-created/webhook-fields', [App\Http\Controllers\User\ShopifyAutomationController::class, 'webhookFieldsCheckoutsCreated'])->name('shopify.automations.checkouts_created.fields');
                Route::post('/settings/shopify/automations/checkouts-created', [App\Http\Controllers\User\ShopifyAutomationController::class, 'updateCheckoutsCreated'])->name('shopify.automations.checkouts_created.update');
                Route::get('/settings/shopify/automations/orders-fulfilled/webhook-fields', [App\Http\Controllers\User\ShopifyAutomationController::class, 'webhookFieldsOrderFulfilled'])->name('shopify.automations.orders_fulfilled.fields');
                Route::post('/settings/shopify/automations/orders-fulfilled', [App\Http\Controllers\User\ShopifyAutomationController::class, 'updateOrderFulfilled'])->name('shopify.automations.orders_fulfilled.update');
                Route::get('/settings/shopify/automations/orders-fulfilled/notification/webhook-fields', [App\Http\Controllers\User\ShopifyAutomationController::class, 'webhookFieldsOrderFulfilledNotification'])->name('shopify.automations.orders_fulfilled.notification.fields');
                Route::post('/settings/shopify/automations/orders-fulfilled/notification', [App\Http\Controllers\User\ShopifyAutomationController::class, 'updateOrderFulfilledNotification'])->name('shopify.automations.orders_fulfilled.notification.update');

                Route::get('/settings/woocommerce/automations', [WooCommerceAutomationController::class, 'index'])->name('woocommerce.automations.index');
                Route::get('/settings/woocommerce/automations/template/{templateId}/variables', [WooCommerceAutomationController::class, 'templateVariables'])->whereNumber('templateId');
                Route::get('/settings/woocommerce/automations/order-created/webhook-fields', [WooCommerceAutomationController::class, 'webhookFields'])->name('woocommerce.automations.order_created.fields');
                Route::post('/settings/woocommerce/automations/order-created', [WooCommerceAutomationController::class, 'updateOrderCreated'])->name('woocommerce.automations.order_created.update');
                Route::get('/settings/woocommerce/automations/add-to-cart/webhook-fields', [WooCommerceAutomationController::class, 'webhookFieldsAddToCart'])->name('woocommerce.automations.add_to_cart.fields');
                Route::post('/settings/woocommerce/automations/add-to-cart', [WooCommerceAutomationController::class, 'updateAddToCart'])->name('woocommerce.automations.add_to_cart.update');
                Route::get('/settings/woocommerce/automations/order-updated/{status}/config', [WooCommerceAutomationController::class, 'orderUpdatedStatusConfig'])
                    ->where('status', 'pending|processing|on-hold|completed|cancelled|refunded|failed')
                    ->name('woocommerce.automations.order_updated.config');
                Route::get('/settings/woocommerce/automations/order-updated/{status}/webhook-fields', [WooCommerceAutomationController::class, 'webhookFieldsOrderUpdated'])
                    ->where('status', 'pending|processing|on-hold|completed|cancelled|refunded|failed')
                    ->name('woocommerce.automations.order_updated.fields');
                Route::post('/settings/woocommerce/automations/order-updated/{status}', [WooCommerceAutomationController::class, 'updateOrderUpdated'])
                    ->where('status', 'pending|processing|on-hold|completed|cancelled|refunded|failed')
                    ->name('woocommerce.automations.order_updated.update');
                Route::get('/settings/woocommerce/message-logs', [App\Http\Controllers\User\WooCommerceMessageLogController::class, 'index'])->name('woocommerce.logs.index');
                Route::get('/settings/woocommerce/message-logs/export', [App\Http\Controllers\User\WooCommerceMessageLogController::class, 'export'])->name('woocommerce.logs.export');

                Route::post('/settings/salla/customer-sync', [App\Http\Controllers\Salla\CustomerSyncController::class, 'sync'])->name('salla.customer.sync');

                Route::get('/settings/salla/message-logs/export', [App\Http\Controllers\User\SallaMessageLogController::class, 'export'])->name('salla.logs.export');
                Route::get('/settings/zid/message-logs/export', [App\Http\Controllers\User\ZidMessageLogController::class, 'export'])->name('zid.logs.export');
                Route::get('/salla-auth', [App\Http\Controllers\Salla\SallaAuthController::class, 'storeAuth']);
                Route::get('/salla/public/auth/callback', [App\Http\Controllers\Salla\SallaAuthController::class, 'callback']);
                Route::get('/reports/agent', [App\Http\Controllers\User\ReportController::class, 'agentReports'])->name('reports.agent');
                Route::get('/reports/general', [App\Http\Controllers\User\ReportController::class, 'index'])->name('reports.general');
                Route::get('/reports/label-count', [App\Http\Controllers\User\ReportController::class, 'countContactsByLabel']);
                Route::get('/reports/campaign-analytics', [App\Http\Controllers\User\ReportController::class, 'campaignAnalytics'])->name('reports.campaign-analytics');
                Route::get('/reports/campaign-analytics/export', [App\Http\Controllers\User\ReportController::class, 'exportCampaignAnalytics'])->name('reports.campaign-analytics.export');

                Route::get('/tickets/{status}', [App\Http\Controllers\User\ChatTicketController::class, 'index']);
                Route::put('/tickets/{uuid}/update', [App\Http\Controllers\User\ChatTicketController::class, 'update']);
                Route::put('/tickets/{uuid}/assign', [App\Http\Controllers\User\ChatTicketController::class, 'assign']);

                Route::post('/contacts/chat-view-details', [App\Http\Controllers\User\ContactController::class, 'chatViewDetails']);
                Route::post('/contacts/bulk-delete', [App\Http\Controllers\User\ContactController::class, 'bulkDelete'])->name('contacts.bulk-delete');
                Route::post('/contacts/bulk-update-attribute', [App\Http\Controllers\User\ContactController::class, 'bulkUpdateAttribute'])->name('contacts.bulk-update-attribute');
                Route::get('/contacts/export', [App\Http\Controllers\User\ContactController::class, 'export'])->name('contacts.bulk-export');
                Route::post('/contacts/import/preview', [App\Http\Controllers\User\ContactImportController::class, 'preview'])->name('contacts.import.preview');
                Route::get('/contacts/import/{batchUuid}/status', [App\Http\Controllers\User\ContactImportController::class, 'status'])->name('contacts.import.status');
                Route::post('/contacts/import/{batchUuid}/mapping', [App\Http\Controllers\User\ContactImportController::class, 'map'])->name('contacts.import.mapping');
                Route::post('/contacts/import/{batchUuid}/normalize', [App\Http\Controllers\User\ContactImportController::class, 'normalize'])->name('contacts.import.normalize');
                Route::get('/contacts/import/{batchUuid}/rows', [App\Http\Controllers\User\ContactImportController::class, 'rows'])->name('contacts.import.rows');
                Route::delete('/contacts/import/{batchUuid}/rows/clear-invalid', [App\Http\Controllers\User\ContactImportController::class, 'clearInvalidRows'])->name('contacts.import.rows.clear-invalid');
                Route::patch('/contacts/import/{batchUuid}/rows/{rowId}', [App\Http\Controllers\User\ContactImportController::class, 'updateRow'])->name('contacts.import.rows.update');
                Route::delete('/contacts/import/{batchUuid}/rows/{rowId}', [App\Http\Controllers\User\ContactImportController::class, 'deleteRow'])->name('contacts.import.rows.delete');
                Route::post('/contacts/import/{batchUuid}/group-assignment', [App\Http\Controllers\User\ContactImportController::class, 'assignGroups'])->name('contacts.import.groups');
                Route::post('/contacts/import/{batchUuid}/commit', [App\Http\Controllers\User\ContactImportController::class, 'commit'])->name('contacts.import.commit');
                Route::get('/contacts/import/{batchUuid}/invalid-report.csv', [App\Http\Controllers\User\ContactImportController::class, 'invalidReportCsv'])->name('contacts.import.invalid-report.csv');
                Route::get('/contacts/import/{batchUuid}/report.csv', [App\Http\Controllers\User\ContactImportController::class, 'reportCsv'])->name('contacts.import.report.csv');

                Route::get('/contacts/{uuid?}', [App\Http\Controllers\User\ContactController::class, 'index'])->name('contacts');
                Route::post('/contacts', [App\Http\Controllers\User\ContactController::class, 'store']);
                Route::post('/contacts/import', [App\Http\Controllers\User\ContactController::class, 'import']);
                Route::post('/contacts/{uuid}', [App\Http\Controllers\User\ContactController::class, 'update']);
                Route::put('/contacts/favorite/{uuid}', [App\Http\Controllers\User\ContactController::class, 'favorite']);
                Route::delete('/contacts', [App\Http\Controllers\User\ContactController::class, 'delete']);

                Route::get('/contact-groups/{uuid?}', [App\Http\Controllers\User\ContactGroupController::class, 'index']);
                Route::post('/contact-groups', [App\Http\Controllers\User\ContactGroupController::class, 'store']);
                Route::post('/contact-groups/import', [App\Http\Controllers\User\ContactGroupController::class, 'import']);
                Route::post('/contact-groups/{uuid}', [App\Http\Controllers\User\ContactGroupController::class, 'update']);
                Route::delete('/contact-groups', [App\Http\Controllers\User\ContactGroupController::class, 'delete']);
                Route::delete('/contact-groups/{uuid}', [App\Http\Controllers\User\ContactGroupController::class, 'deleteSingle'])->name('contact-groups.delete-single');

                Route::put('/contacts/{uuid}/labels', [App\Http\Controllers\User\ContactController::class, 'updateLabels'])->name('contacts.labels.update');
                Route::get('/contacts/{uuid}/labels', [App\Http\Controllers\User\ContactController::class, 'getLabels']);

                Route::put('/contacts/{uuid}/bot-toggle', [App\Http\Controllers\User\ContactController::class, 'toggleBot'])->name('contacts.bot.toggle');
                Route::put('/contacts/{uuid}/block-toggle', [App\Http\Controllers\User\ContactController::class, 'toggleBlock'])->name('contacts.block.toggle');

                Route::get('/campaigns/contact-groups/search', [App\Http\Controllers\User\CampaignController::class, 'searchContactGroups'])->name('campaigns.contact-groups.search');
                Route::get('/campaigns/{uuid?}', [App\Http\Controllers\User\CampaignController::class, 'index'])->name('campaigns');
                Route::post('/campaigns', [App\Http\Controllers\User\CampaignController::class, 'store']);
                Route::get('/campaigns/export/{uuid?}', [App\Http\Controllers\User\CampaignController::class, 'export']);
                Route::get('/campaigns/reports/export-statuses', [App\Http\Controllers\User\CampaignController::class, 'exportCampaignStatuses'])->name('campaigns.export-statuses');
                Route::post('/campaigns/preflight', [App\Http\Controllers\User\CampaignController::class, 'preflight'])->name('campaigns.preflight');
                Route::delete('/campaigns/{uuid?}', [App\Http\Controllers\User\CampaignController::class, 'delete']);
                Route::put('/campaigns/{uuid}/schedule', [App\Http\Controllers\User\CampaignController::class, 'updateSchedule'])->name('campaigns.schedule.update');
                Route::post('/campaigns/{uuid}/cancel', [App\Http\Controllers\User\CampaignController::class, 'cancel'])->name('campaigns.cancel');
                Route::post('/campaigns/{uuid}/cancel-schedule', [App\Http\Controllers\User\CampaignController::class, 'cancelSchedule'])->name('campaigns.schedule.cancel');
                Route::post('/campaigns/{uuid}/resend-failed', [App\Http\Controllers\User\CampaignController::class, 'resendFailed'])->name('campaigns.resend-failed');

                Route::post('/templates/sync', [App\Http\Controllers\User\TemplateController::class, 'sync'])->name('templates.sync');
                Route::get('/templates/sync/status', [App\Http\Controllers\User\TemplateController::class, 'syncStatus'])->name('templates.sync-status');
                Route::match(['get', 'post'], '/templates/create', [App\Http\Controllers\User\TemplateController::class, 'create']);
                Route::get('/templates/{uuid}/show', [App\Http\Controllers\User\TemplateController::class, 'show'])->name('templates.show');
                Route::get('/templates/{uuid}/copy', [App\Http\Controllers\User\TemplateController::class, 'copy'])->name('templates.copy');
                Route::get('/templates/{uuid?}', [App\Http\Controllers\User\TemplateController::class, 'index']);
                Route::post('/templates', [App\Http\Controllers\User\TemplateController::class, 'store']);
                Route::post('/templates/{uuid}', [App\Http\Controllers\User\TemplateController::class, 'update']);
                Route::delete('/templates/{uuid}', [App\Http\Controllers\User\TemplateController::class, 'delete']);

                // Trigger Groups
                Route::get('/automation/triggers', [App\Http\Controllers\User\TriggerGroupController::class, 'index'])->name('triggers.index');
                Route::get('/automation/triggers/check-keyword', [App\Http\Controllers\User\TriggerGroupController::class, 'checkKeyword'])->name('triggers.checkKeyword');
                Route::post('/automation/triggers', [App\Http\Controllers\User\TriggerGroupController::class, 'store'])->name('triggers.store');
                Route::post('/automation/triggers/api-store', [App\Http\Controllers\User\TriggerGroupController::class, 'apiStore'])->name('triggers.apiStore');
                Route::put('/automation/triggers/{uuid}', [App\Http\Controllers\User\TriggerGroupController::class, 'update'])->name('triggers.update');
                Route::delete('/automation/triggers/{uuid}', [App\Http\Controllers\User\TriggerGroupController::class, 'destroy'])->name('triggers.destroy');
                Route::post('/automation/triggers/reorder', [App\Http\Controllers\User\TriggerGroupController::class, 'reorder'])->name('triggers.reorder');
                Route::post('/automation/triggers/{uuid}/toggle', [App\Http\Controllers\User\TriggerGroupController::class, 'toggle'])->name('triggers.toggle');
                Route::post('/automation/triggers/{uuid}/link', [App\Http\Controllers\User\TriggerGroupController::class, 'linkFlow'])->name('triggers.linkFlow');

                // Smart Followup
                Route::get('/automation/smart-followup', [App\Http\Controllers\User\SmartFollowupController::class, 'index'])->name('smart-followup.index');
                Route::post('/automation/smart-followup/rejoin-reminders', [App\Http\Controllers\User\SmartFollowupController::class, 'store'])->name('smart-followup.rejoin-reminders.store');
                Route::put('/automation/smart-followup/rejoin-reminders/{uuid}', [App\Http\Controllers\User\SmartFollowupController::class, 'update'])->name('smart-followup.rejoin-reminders.update');
                Route::delete('/automation/smart-followup/rejoin-reminders/{uuid}', [App\Http\Controllers\User\SmartFollowupController::class, 'destroy'])->name('smart-followup.rejoin-reminders.destroy');
                Route::post('/automation/smart-followup/rejoin-reminders/{uuid}/toggle', [App\Http\Controllers\User\SmartFollowupController::class, 'toggle'])->name('smart-followup.rejoin-reminders.toggle');

                // Knowledge Base
                Route::get('/automation/knowledge-base', [App\Http\Controllers\User\AiKnowledgeBaseController::class, 'index'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('knowledge-base.index');
                Route::post('/automation/knowledge-base', [App\Http\Controllers\User\AiKnowledgeBaseController::class, 'store'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('knowledge-base.store');
                Route::put('/automation/knowledge-base/{uuid}', [App\Http\Controllers\User\AiKnowledgeBaseController::class, 'update'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('knowledge-base.update');
                Route::delete('/automation/knowledge-base/{uuid}', [App\Http\Controllers\User\AiKnowledgeBaseController::class, 'destroy'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('knowledge-base.destroy');
                Route::post('/automation/knowledge-base/{uuid}/toggle', [App\Http\Controllers\User\AiKnowledgeBaseController::class, 'toggle'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('knowledge-base.toggle');

                // Bot Settings
                Route::get('/automation/bot-settings', [App\Http\Controllers\User\BotSettingsController::class, 'index'])->name('bot-settings.index');
                Route::put('/automation/bot-settings', [App\Http\Controllers\User\BotSettingsController::class, 'update'])->name('bot-settings.update');

                // AI Settings
                Route::get('/automation/ai-settings', [App\Http\Controllers\User\AiSettingsController::class, 'index'])->name('ai-settings.index');
                Route::put('/automation/ai-settings', [App\Http\Controllers\User\AiSettingsController::class, 'update'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('ai-settings.update');

                // Escalation Instructions
                Route::get('/automation/escalation', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'index'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.index');
                Route::post('/automation/escalation', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'store'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.store');
                Route::put('/automation/escalation/{uuid}', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'update'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.update');
                Route::delete('/automation/escalation/{uuid}', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'destroy'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.destroy');
                Route::post('/automation/escalation/reorder', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'reorder'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.reorder');
                Route::post('/automation/escalation/{uuid}/toggle', [App\Http\Controllers\User\AiEscalationInstructionController::class, 'toggle'])->middleware(\App\Http\Middleware\EnsureAutomationAiAvailable::class)->name('escalation.toggle');

                // Automation Test / Simulator
                Route::get('/automation/test', [App\Http\Controllers\User\AutomationTestController::class, 'index'])->name('automation-test.index');
                Route::get('/automation/test/context', [App\Http\Controllers\User\AutomationTestController::class, 'getTestContext'])->name('automation-test.context');
                Route::post('/automation/test/trigger', [App\Http\Controllers\User\AutomationTestController::class, 'testTrigger'])->name('automation-test.trigger');
                Route::post('/automation/test/flow/start', [App\Http\Controllers\User\AutomationTestController::class, 'startFlow'])->name('automation-test.flow-start');
                Route::post('/automation/test/flow/step', [App\Http\Controllers\User\AutomationTestController::class, 'stepFlow'])->name('automation-test.flow-step');
                Route::post('/automation/test/ai', [App\Http\Controllers\User\AutomationTestController::class, 'testAi'])->name('automation-test.ai');

                Route::resource('pre-created-replies', App\Http\Controllers\User\PreCreatedReplyController::class)->parameters([
                    'pre-created-replies' => 'uuid',
                ]);

                Route::get('/support/{uuid?}', [App\Http\Controllers\User\TicketController::class, 'index'])->name('support');
                Route::post('/support', [App\Http\Controllers\User\TicketController::class, 'store']);
                Route::post('/support/{uuid}/comment', [App\Http\Controllers\User\TicketController::class, 'comment']);
                Route::post('/support/{uuid}/status', [App\Http\Controllers\User\TicketController::class, 'changeStatus']);
                Route::post('/support/{uuid}/priority', [App\Http\Controllers\User\TicketController::class, 'changePriority']);

                Route::match(['get', 'post'], '/messages', [App\Http\Controllers\User\MessageController::class, 'index']);
                Route::match(['get', 'post'], '/message-templates', [App\Http\Controllers\User\TemplateController::class, 'index']);
                Route::match(['get', 'post'], '/instances', [App\Http\Controllers\User\InstanceController::class, 'index']);

                Route::get('/team', [App\Http\Controllers\User\TeamController::class, 'index'])->name('team');
                Route::post('/team/invite', [App\Http\Controllers\User\TeamController::class, 'invite'])->name('team.store');
                Route::put('/team/{uuid}', [App\Http\Controllers\User\TeamController::class, 'update'])->name('team.update');

                Route::group(['middleware' => 'check.client.role'], function () {
                    Route::get('/settings', [App\Http\Controllers\User\SettingController::class, 'index']);
                    Route::get('/settings/m', [App\Http\Controllers\User\SettingController::class, 'mobileView']);
                    Route::get('/web-widget', [App\Http\Controllers\WebWidgetController::class, 'index'])->name('user.web-widget.index');
                    Route::post('/web-widget', [App\Http\Controllers\WebWidgetController::class, 'store'])->name('user.web-widget.store');

                    Route::match(['get', 'post'], '/settings/campaign', [App\Http\Controllers\User\SettingController::class, 'campaign']);

                    Route::get('/settings/whatsapp', [App\Http\Controllers\User\SettingController::class, 'viewWhatsappSettings']);
                    Route::get('/settings/plugins', [App\Http\Controllers\User\PluginController::class, 'index'])->name('settings.plugins');
                    Route::post('/settings/plugins/woocommerce', [WooCommerceIntegrationController::class, 'store'])->name('settings.plugins.woocommerce.store');
                    Route::post('/settings/plugins/woocommerce/test', [WooCommerceIntegrationController::class, 'testConnection'])->name('settings.plugins.woocommerce.test');
                    Route::post('/settings/plugins/woocommerce/subscriptions', [WooCommerceIntegrationController::class, 'createSubscription'])->name('settings.plugins.woocommerce.subscription.create');
                    Route::delete('/settings/plugins/woocommerce/subscriptions/{topic}', [WooCommerceIntegrationController::class, 'deleteSubscription'])
                        ->where('topic', 'order\\.created|order\\.updated|action\\.woocommerce_order_status_changed|action\\.woocommerce_add_to_cart')
                        ->name('settings.plugins.woocommerce.subscription.delete');
                    Route::get('/settings/woocommerce', [App\Http\Controllers\User\PluginController::class, 'wooCommerceSettings'])->name('settings.woocommerce');
                    Route::get('/settings/zid', [App\Http\Controllers\User\PluginController::class, 'zidSettings'])->name('settings.zid');
                    Route::get('/plugin/download/{name}', [App\Http\Controllers\User\PluginController::class, 'download'])->name('plugin.download');
                    Route::post('/settings/whatsapp/refresh', [App\Http\Controllers\User\SettingController::class, 'refreshWhatsappData']);
                    Route::get('/settings/whatsapp/template-sync-status', [App\Http\Controllers\User\SettingController::class, 'whatsappTemplateSyncStatus']);
                    Route::post('/settings/whatsapp/token', [App\Http\Controllers\User\SettingController::class, 'updateToken']);
                    Route::post('/settings/whatsapp', [App\Http\Controllers\User\SettingController::class, 'storeWhatsappSettings']);
                    Route::post('/settings/whatsapp/business-profile', [App\Http\Controllers\User\SettingController::class, 'whatsappBusinessProfileUpdate']);
                    Route::delete('/settings/whatsapp/business-profile', [App\Http\Controllers\User\SettingController::class, 'deleteWhatsappIntegration']);
                    Route::post('/settings/whatsapp/update-coexistence', [App\Http\Controllers\User\SettingController::class, 'updateWhatsappForCoExistence']);


                    Route::get('/settings/labels', [App\Http\Controllers\User\SettingController::class, 'labels'])->name('settings.labels');
                    Route::post('/settings/labels', [App\Http\Controllers\User\SettingController::class, 'labels']);
                    Route::post('/settings/labels/create-group', [App\Http\Controllers\User\SettingController::class, 'createGroupFromLabel']);
                    Route::put('/settings/labels/{label}', [App\Http\Controllers\User\SettingController::class, 'updateLabel'])->name('settings.labels.update');
                    Route::delete('/settings/labels/{label}', [App\Http\Controllers\User\SettingController::class, 'deleteLabel'])->name('settings.labels.delete');


                    Route::match(['get', 'post'], '/settings/contacts', [App\Http\Controllers\User\SettingController::class, 'contacts']);
                    Route::match(['get', 'post'], '/settings/tickets', [App\Http\Controllers\User\SettingController::class, 'tickets']);
                    Route::get('/settings/notifications', [App\Http\Controllers\User\SettingController::class, 'emailSettings']);
                    Route::post('/settings/notifications/email', [App\Http\Controllers\User\SettingController::class, 'updateEmailSettings']);
                    Route::post('/settings/notifications/email/test', [App\Http\Controllers\User\SettingController::class, 'testEmailSettings']);
                    Route::resource('contact-fields', App\Http\Controllers\User\ContactFieldController::class);
                    Route::post('/contact-fields/update-positions', [App\Http\Controllers\User\ContactFieldController::class, 'updatePositions']);

                    Route::delete('/team/{uuid}', [App\Http\Controllers\User\TeamController::class, 'delete'])->name('team.destroy');

                    Route::get('/developer-tools/access-tokens', [App\Http\Controllers\User\DeveloperController::class, 'index']);
                    Route::post('/developer-tools/access-tokens', [App\Http\Controllers\User\DeveloperController::class, 'store']);
                    Route::delete('/developer-tools/access-tokens/{uuid}', [App\Http\Controllers\User\DeveloperController::class, 'delete']);
                });

                // Notifications are available to every active team role. Each action
                // remains scoped to the authenticated user's own organization records.
                Route::get('/notifications', [App\Http\Controllers\User\NotificationController::class, 'index']);
                Route::get('/notifications/unread-count', [App\Http\Controllers\User\NotificationController::class, 'unreadCount'])
                    ->withoutMiddleware([
                        \App\Http\Middleware\HandleInertiaRequests::class,
                        \App\Http\Middleware\Localization::class,
                        \App\Http\Middleware\IsTrial::class,
                    ]);
                Route::get('/notifications/managed-unread', [App\Http\Controllers\User\NotificationController::class, 'pendingManaged'])
                    ->withoutMiddleware([
                        \App\Http\Middleware\HandleInertiaRequests::class,
                        \App\Http\Middleware\Localization::class,
                        \App\Http\Middleware\IsTrial::class,
                    ]);
                Route::post('/notifications/{id}/click', [App\Http\Controllers\User\NotificationController::class, 'markClicked'])
                    ->withoutMiddleware(\App\Http\Middleware\IsTrial::class);
                Route::post('/notifications/mark-all-read', [App\Http\Controllers\User\NotificationController::class, 'markAllRead']);

                //Route::get('/whatsapp/message', [App\Http\Controllers\User\WhatsappController::class, 'sendMessage']);
                Route::resource('notes', App\Http\Controllers\User\ChatNoteController::class);
            });
        });
    });
});

//Admin Panel Routes
Route::prefix('admin')->middleware(['auth:admin', 'admin.permission'])->group(function () {
    Route::get('/chatbot-studio', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'index']);
    Route::get('/chatbot-studio/organizations', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'organizations']);
    Route::get('/chatbot-studio/drafts', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'drafts']);
    Route::post('/chatbot-studio/generate', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'generate'])->middleware('throttle:10,1');
    Route::get('/chatbot-studio/drafts/{id}', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'show']);
    Route::put('/chatbot-studio/drafts/{id}', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'update']);
    Route::post('/chatbot-studio/drafts/{id}/publish', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'publish']);
    Route::post('/chatbot-studio/drafts/{id}/cancel', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'cancel']);
    Route::post('/chatbot-studio/drafts/{id}/retry', [App\Http\Controllers\Admin\ChatbotStudioController::class, 'retry'])->middleware('throttle:10,1');
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index']);
    Route::resource('users', App\Http\Controllers\Admin\UserController::class);
    Route::post('/organizations/sync-whatsapp-number-statuses', [App\Http\Controllers\Admin\OrganizationController::class, 'syncWhatsappNumberStatuses'])
        ->name('admin.organizations.sync-whatsapp-number-statuses');
    Route::resource('organizations', App\Http\Controllers\Admin\OrganizationController::class);
    Route::get('/subscription-cancellation-requests', [App\Http\Controllers\Admin\SubscriptionCancellationRequestController::class, 'index']);
    Route::post('/subscription-cancellation-requests/{cancellationRequest}/review', [App\Http\Controllers\Admin\SubscriptionCancellationRequestController::class, 'markReviewed']);
    Route::get('/organizations/{uuid}/chats/export', [App\Http\Controllers\Admin\OrganizationController::class, 'exportChats'])
        ->middleware('throttle:10,1')
        ->name('admin.organizations.chats.export');
    Route::post('/organizations/{uuid}/subscription/extend', [App\Http\Controllers\Admin\OrganizationController::class, 'extendSubscription']);
    Route::post('/organizations/{uuid}/subscription/renew', [App\Http\Controllers\Admin\OrganizationController::class, 'renewSubscription']);
    Route::get('/organizations/{id}/login-as', [App\Http\Controllers\AuthController::class, 'loginAs']);
    /*Route::resource('blog/posts', App\Http\Controllers\Admin\BlogController::class);
    Route::resource('blog/categories', App\Http\Controllers\Admin\BlogCategoryController::class);
    Route::resource('blog/authors', App\Http\Controllers\Admin\BlogAuthorController::class);
    Route::resource('blog/tags', App\Http\Controllers\Admin\BlogTagController::class);*/
    Route::resource('tax-rates', App\Http\Controllers\Admin\TaxController::class);
    Route::resource('coupons', App\Http\Controllers\Admin\CouponController::class);
    Route::resource('faqs', App\Http\Controllers\Admin\FaqController::class);
    Route::resource('testimonials', App\Http\Controllers\Admin\TestimonialController::class);
    Route::resource('plans', App\Http\Controllers\Admin\SubscriptionPlanController::class);
    Route::resource('team/users', App\Http\Controllers\Admin\TeamController::class);
    Route::resource('team/roles', App\Http\Controllers\Admin\RoleController::class);
    Route::resource('billing', App\Http\Controllers\Admin\BillingController::class);
    Route::resource('addons', App\Http\Controllers\Admin\AddonController::class);
    Route::resource('resource-addons', App\Http\Controllers\Admin\ResourceAddonController::class)->only(['index', 'store', 'destroy']);
    Route::resource('otp-config', App\Http\Controllers\Admin\OtpConfigController::class);
    Route::post('addons/install', [App\Http\Controllers\Admin\AddonController::class, 'install']);
    Route::post('addons/update', [App\Http\Controllers\Admin\AddonController::class, 'update']);
    Route::post('/addons/setup/google-recaptcha', [App\Http\Controllers\Admin\AddonController::class, 'store']);
    Route::post('/addons/setup/google-analytics', [App\Http\Controllers\Admin\AddonController::class, 'store']);
    Route::post('/addons/setup/google-maps', [App\Http\Controllers\Admin\AddonController::class, 'store']);
    Route::post('/addons/setup/google-authenticator', [App\Http\Controllers\Admin\AddonController::class, 'store']);
    Route::resource('payment-gateways', App\Http\Controllers\Admin\PaymentGatewayController::class)->only(['index', 'show', 'update']);
    Route::get('/languages/{language}/export', [App\Http\Controllers\Admin\LanguageController::class, 'export']);
    Route::post('/languages/{language}/import', [App\Http\Controllers\Admin\LanguageController::class, 'import']);
    Route::get('/languages/{language}/translations', [App\Http\Controllers\Admin\LanguageController::class, 'translations']);
    Route::get('/languages/{language}/default', [App\Http\Controllers\Admin\LanguageController::class, 'setDefault']);
    Route::resource('languages', App\Http\Controllers\Admin\LanguageController::class);
    Route::post('/translations/{languageCode}/{key}', [App\Http\Controllers\Admin\LanguageController::class, 'updateTranslation']);

    /*Route::get('/pages', [App\Http\Controllers\Admin\PageController::class, 'index']);
    Route::get('/users/{uuid}/organizations', [App\Http\Controllers\Admin\CustomerController::class, 'userOrganizations']);
    Route::get('/subscriptions', [App\Http\Controllers\Admin\SubscriptionController::class, 'index']);*/
    Route::get('/payment-logs', [App\Http\Controllers\Admin\PaymentController::class, 'index']);

    Route::get('/support/{uuid?}', [App\Http\Controllers\Admin\TicketController::class, 'index'])->name('tickets');
    Route::post('/support', [App\Http\Controllers\Admin\TicketController::class, 'store']);
    Route::post('/support/{uuid}/comment', [App\Http\Controllers\Admin\TicketController::class, 'comment']);
    Route::post('/support/{uuid}/status', [App\Http\Controllers\Admin\TicketController::class, 'changeStatus']);
    Route::post('/support/{uuid}/priority', [App\Http\Controllers\Admin\TicketController::class, 'changePriority']);
    Route::post('/support/{uuid}/assign', [App\Http\Controllers\Admin\TicketController::class, 'assign']);

    Route::get('/settings', [App\Http\Controllers\Admin\SettingController::class, 'index']);
    Route::match(['get', 'post'], '/settings/general', [App\Http\Controllers\Admin\SettingController::class, 'general']);
    Route::put('/settings', [App\Http\Controllers\Admin\SettingController::class, 'update']);
    Route::get('/settings/smtp', [App\Http\Controllers\Admin\SettingController::class, 'email']);
    Route::get('/settings/broadcast-drivers', [App\Http\Controllers\Admin\SettingController::class, 'broadcast_driver']);
    Route::match(['get', 'post'], '/settings/timezone', [App\Http\Controllers\Admin\SettingController::class, 'timezone']);
    Route::get('/settings/email-templates', [App\Http\Controllers\Admin\EmailTemplateController::class, 'index']);
    Route::get('/settings/managed-notifications', [App\Http\Controllers\Admin\ManagedNotificationController::class, 'index']);
    Route::get('/settings/notification-senders', [App\Http\Controllers\Admin\NotificationEmailSenderController::class, 'index']);
    Route::post('/settings/notification-senders', [App\Http\Controllers\Admin\NotificationEmailSenderController::class, 'store']);
    Route::put('/settings/notification-senders/{sender}', [App\Http\Controllers\Admin\NotificationEmailSenderController::class, 'update']);
    Route::get('/settings/notification-deliveries', [App\Http\Controllers\Admin\ManagedNotificationController::class, 'deliveries']);
    Route::put('/settings/managed-notifications/{typeKey}', [App\Http\Controllers\Admin\ManagedNotificationController::class, 'update']);
    Route::post('/settings/managed-notifications/{typeKey}/test-email', [App\Http\Controllers\Admin\ManagedNotificationController::class, 'testEmail'])
        ->middleware('throttle:6,1');
    Route::get('/settings/email-template/{id}', [App\Http\Controllers\Admin\EmailTemplateController::class, 'show']);
    Route::put('/settings/email-template/{id}', [App\Http\Controllers\Admin\EmailTemplateController::class, 'update']);
    Route::get('/settings/pages', [App\Http\Controllers\Admin\PagesController::class, 'index']);
    Route::post('/settings/pages', [App\Http\Controllers\Admin\PagesController::class, 'store']);
    Route::get('/settings/page/{id}', [App\Http\Controllers\Admin\PagesController::class, 'show']);
    Route::put('/settings/page/{id}', [App\Http\Controllers\Admin\PagesController::class, 'update']);
    Route::delete('/settings/page/{id}', [App\Http\Controllers\Admin\PagesController::class, 'delete']);
    Route::match(['get', 'post'], '/settings/billing', [App\Http\Controllers\Admin\SettingController::class, 'billing']);
    Route::get('/settings/storage', [App\Http\Controllers\Admin\SettingController::class, 'storage']);
    Route::get('/settings/socials', [App\Http\Controllers\Admin\SettingController::class, 'socials']);
    Route::get('/settings/subscription', [App\Http\Controllers\Admin\SettingController::class, 'subscription']);

    Route::get('/updates', [App\Http\Controllers\Admin\UpdateController::class, 'index']);
    Route::get('/updates/check', [App\Http\Controllers\Admin\UpdateController::class, 'checkUpdate']);
    Route::post('/update', [App\Http\Controllers\Admin\UpdateController::class, 'update']);

    Route::get('/user-logs/notifications', [App\Http\Controllers\Admin\NotificationController::class, 'index']);
    Route::get('/notifications', [App\Http\Controllers\Admin\ManagedNotificationInboxController::class, 'index']);
    Route::post('/notifications/{id}/read', [App\Http\Controllers\Admin\ManagedNotificationInboxController::class, 'markRead']);
    Route::get('/user-logs/emails', [App\Http\Controllers\Admin\EmailLogController::class, 'index']);
    Route::get('/salla/app-installations', [SallaAppInstallationController::class, 'index']);

    // Webhook Failures - AC-1.5.4 & AC-1.5.5
    Route::get('/webhook-failures', [App\Http\Controllers\Admin\WebhookFailureController::class, 'index']);
    Route::get('/webhook-failures/{id}', [App\Http\Controllers\Admin\WebhookFailureController::class, 'show']);
    Route::post('/webhook-failures/{id}/retry', [App\Http\Controllers\Admin\WebhookFailureController::class, 'retry']);
    Route::delete('/webhook-failures/{id}', [App\Http\Controllers\Admin\WebhookFailureController::class, 'destroy']);
});
