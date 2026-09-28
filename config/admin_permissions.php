<?php

/*
 * The canonical admin RBAC catalogue.  The module/action pairs are persisted
 * in role_permissions and are also used by the route middleware and Inertia.
 */
return [
    'modules' => [
        'dashboard' => ['view'],
        'customers' => ['view', 'create', 'edit', 'delete'],
        'organizations' => ['view', 'create', 'edit', 'delete', 'login_as'],
        'billing' => ['view', 'create', 'edit', 'delete'],
        'support' => ['view', 'create', 'edit', 'delete'],
        'team' => ['view', 'create', 'edit', 'delete'],
        'roles' => ['view', 'create', 'edit', 'delete'],
        'subscription_plans' => ['view', 'create', 'edit', 'delete'],
        'settings' => [
            'view', 'edit', 'general', 'timezone', 'broadcast_driver',
            'payment_gateways', 'smtp', 'email_templates', 'billing',
            'tax_rates', 'coupons', 'frontend', 'storage', 'socials', 'subscription',
        ],
        'addons' => ['view', 'create', 'edit', 'delete'],
        'resource_addons' => ['view', 'create', 'edit', 'delete'],
        'otp_config' => ['view', 'create', 'edit', 'delete'],
        'languages' => ['view', 'create', 'edit', 'delete'],
        'logs' => ['view'],
        'notifications' => ['view', 'edit', 'receive'],
        'updates' => ['view', 'edit'],
        'webhook_failures' => ['view', 'edit', 'delete'],
    ],

    /*
     * URI patterns are relative to the application root.  First matching
     * method/URI pair wins; every admin route for a non-super-admin must map.
     */
    'routes' => [
        ['POST', 'admin/organizations/*/subscription/extend', 'organizations', 'edit'],
        ['POST', 'admin/organizations/*/subscription/renew', 'organizations', 'edit'],
        ['GET', 'admin/chatbot-studio*', 'organizations', 'view'],
        ['POST', 'admin/chatbot-studio*', 'organizations', 'edit'],
        ['PUT', 'admin/chatbot-studio*', 'organizations', 'edit'],
        ['GET', 'admin/dashboard', 'dashboard', 'view'],

        ['GET', 'admin/users', 'customers', 'view'], ['GET', 'admin/users/create', 'customers', 'create'], ['GET', 'admin/users/*/edit', 'customers', 'edit'], ['GET', 'admin/users/*', 'customers', 'view'], ['POST', 'admin/users', 'customers', 'create'], ['PUT', 'admin/users/*', 'customers', 'edit'], ['PATCH', 'admin/users/*', 'customers', 'edit'], ['DELETE', 'admin/users/*', 'customers', 'delete'],
        ['GET', 'admin/organizations', 'organizations', 'view'], ['GET', 'admin/organizations/create', 'organizations', 'create'], ['GET', 'admin/organizations/*/login-as', 'organizations', 'login_as'], ['GET', 'admin/organizations/*/edit', 'organizations', 'edit'], ['GET', 'admin/organizations/*', 'organizations', 'view'], ['POST', 'admin/organizations', 'organizations', 'create'], ['POST', 'admin/organizations/sync-whatsapp-number-statuses', 'organizations', 'edit'], ['PUT', 'admin/organizations/*', 'organizations', 'edit'], ['PATCH', 'admin/organizations/*', 'organizations', 'edit'], ['DELETE', 'admin/organizations/*', 'organizations', 'delete'],
        ['GET', 'admin/payment-logs', 'billing', 'view'], ['GET', 'admin/billing/create', 'billing', 'create'], ['GET', 'admin/billing/*/edit', 'billing', 'edit'], ['GET', 'admin/billing*', 'billing', 'view'], ['POST', 'admin/billing*', 'billing', 'create'], ['PUT', 'admin/billing*', 'billing', 'edit'], ['PATCH', 'admin/billing*', 'billing', 'edit'], ['DELETE', 'admin/billing*', 'billing', 'delete'],
        ['GET', 'admin/support/create', 'support', 'create'], ['GET', 'admin/support*', 'support', 'view'], ['POST', 'admin/support', 'support', 'create'], ['POST', 'admin/support/*', 'support', 'edit'],
        ['GET', 'admin/team/users', 'team', 'view'], ['GET', 'admin/team/users/create', 'team', 'create'], ['GET', 'admin/team/users/*/edit', 'team', 'edit'], ['GET', 'admin/team/users/*', 'team', 'view'], ['POST', 'admin/team/users', 'team', 'create'], ['PUT', 'admin/team/users/*', 'team', 'edit'], ['PATCH', 'admin/team/users/*', 'team', 'edit'], ['DELETE', 'admin/team/users/*', 'team', 'delete'],
        ['GET', 'admin/team/roles', 'roles', 'view'], ['GET', 'admin/team/roles/create', 'roles', 'create'], ['GET', 'admin/team/roles/*/edit', 'roles', 'edit'], ['GET', 'admin/team/roles/*', 'roles', 'view'], ['POST', 'admin/team/roles', 'roles', 'create'], ['PUT', 'admin/team/roles/*', 'roles', 'edit'], ['PATCH', 'admin/team/roles/*', 'roles', 'edit'], ['DELETE', 'admin/team/roles/*', 'roles', 'delete'],
        ['GET', 'admin/plans', 'subscription_plans', 'view'], ['GET', 'admin/plans/create', 'subscription_plans', 'create'], ['GET', 'admin/plans/*/edit', 'subscription_plans', 'edit'], ['GET', 'admin/plans/*', 'subscription_plans', 'view'], ['POST', 'admin/plans', 'subscription_plans', 'create'], ['PUT', 'admin/plans/*', 'subscription_plans', 'edit'], ['PATCH', 'admin/plans/*', 'subscription_plans', 'edit'], ['DELETE', 'admin/plans/*', 'subscription_plans', 'delete'],
        ['GET', 'admin/tax-rates*', 'settings', 'tax_rates'], ['POST', 'admin/tax-rates*', 'settings', 'tax_rates'], ['PUT', 'admin/tax-rates*', 'settings', 'tax_rates'], ['PATCH', 'admin/tax-rates*', 'settings', 'tax_rates'], ['DELETE', 'admin/tax-rates*', 'settings', 'tax_rates'],
        ['GET', 'admin/coupons*', 'settings', 'coupons'], ['POST', 'admin/coupons*', 'settings', 'coupons'], ['PUT', 'admin/coupons*', 'settings', 'coupons'], ['PATCH', 'admin/coupons*', 'settings', 'coupons'], ['DELETE', 'admin/coupons*', 'settings', 'coupons'],
        ['GET', 'admin/faqs*', 'settings', 'frontend'], ['POST', 'admin/faqs*', 'settings', 'frontend'], ['PUT', 'admin/faqs*', 'settings', 'frontend'], ['PATCH', 'admin/faqs*', 'settings', 'frontend'], ['DELETE', 'admin/faqs*', 'settings', 'frontend'],
        ['GET', 'admin/testimonials*', 'settings', 'frontend'], ['POST', 'admin/testimonials*', 'settings', 'frontend'], ['PUT', 'admin/testimonials*', 'settings', 'frontend'], ['PATCH', 'admin/testimonials*', 'settings', 'frontend'], ['DELETE', 'admin/testimonials*', 'settings', 'frontend'],
        ['GET', 'admin/addons/create', 'addons', 'create'], ['GET', 'admin/addons/*/edit', 'addons', 'edit'], ['GET', 'admin/addons*', 'addons', 'view'], ['POST', 'admin/addons/install', 'addons', 'create'], ['POST', 'admin/addons/update', 'addons', 'edit'], ['POST', 'admin/addons/setup/*', 'addons', 'edit'], ['POST', 'admin/addons', 'addons', 'create'], ['PUT', 'admin/addons/*', 'addons', 'edit'], ['PATCH', 'admin/addons/*', 'addons', 'edit'], ['DELETE', 'admin/addons/*', 'addons', 'delete'],
        ['GET', 'admin/resource-addons*', 'resource_addons', 'view'], ['POST', 'admin/resource-addons*', 'resource_addons', 'create'], ['DELETE', 'admin/resource-addons/*', 'resource_addons', 'delete'],
        ['GET', 'admin/otp-config/create', 'otp_config', 'create'], ['GET', 'admin/otp-config/*/edit', 'otp_config', 'edit'], ['GET', 'admin/otp-config*', 'otp_config', 'view'], ['POST', 'admin/otp-config*', 'otp_config', 'create'], ['PUT', 'admin/otp-config/*', 'otp_config', 'edit'], ['PATCH', 'admin/otp-config/*', 'otp_config', 'edit'], ['DELETE', 'admin/otp-config/*', 'otp_config', 'delete'],
        ['GET', 'admin/languages/create', 'languages', 'create'], ['GET', 'admin/languages/*/edit', 'languages', 'edit'], ['GET', 'admin/languages/*/export', 'languages', 'view'], ['POST', 'admin/languages/*/import', 'languages', 'edit'], ['GET', 'admin/languages/*/translations', 'languages', 'view'], ['GET', 'admin/languages/*/default', 'languages', 'edit'], ['POST', 'admin/translations/*', 'languages', 'edit'], ['GET', 'admin/languages*', 'languages', 'view'], ['POST', 'admin/languages', 'languages', 'create'], ['PUT', 'admin/languages/*', 'languages', 'edit'], ['PATCH', 'admin/languages/*', 'languages', 'edit'], ['DELETE', 'admin/languages/*', 'languages', 'delete'],
        ['GET', 'admin/payment-gateways*', 'settings', 'payment_gateways'], ['PUT', 'admin/payment-gateways/*', 'settings', 'payment_gateways'], ['PATCH', 'admin/payment-gateways/*', 'settings', 'payment_gateways'],
        ['GET', 'admin/settings', 'settings', 'view'], ['GET', 'admin/settings/general', 'settings', 'general'], ['POST', 'admin/settings/general', 'settings', 'general'], ['GET', 'admin/settings/timezone', 'settings', 'timezone'], ['POST', 'admin/settings/timezone', 'settings', 'timezone'], ['GET', 'admin/settings/broadcast-drivers', 'settings', 'broadcast_driver'], ['GET', 'admin/settings/smtp', 'settings', 'smtp'], ['GET', 'admin/settings/billing', 'settings', 'billing'], ['POST', 'admin/settings/billing', 'settings', 'billing'], ['GET', 'admin/settings/storage', 'settings', 'storage'], ['GET', 'admin/settings/socials', 'settings', 'socials'], ['GET', 'admin/settings/subscription', 'settings', 'subscription'], ['GET', 'admin/settings/email-template*', 'settings', 'email_templates'], ['PUT', 'admin/settings/email-template/*', 'settings', 'email_templates'], ['GET', 'admin/settings/page*', 'settings', 'frontend'], ['POST', 'admin/settings/pages', 'settings', 'frontend'], ['PUT', 'admin/settings/page/*', 'settings', 'frontend'], ['DELETE', 'admin/settings/page/*', 'settings', 'frontend'], ['PUT', 'admin/settings', 'settings', 'edit'],
        ['GET', 'admin/updates*', 'updates', 'view'], ['POST', 'admin/update', 'updates', 'edit'],
        ['GET', 'admin/settings/managed-notifications', 'notifications', 'view'],
        ['GET', 'admin/settings/notification-senders', 'notifications', 'view'],
        ['POST', 'admin/settings/notification-senders', 'notifications', 'edit'],
        ['PUT', 'admin/settings/notification-senders/*', 'notifications', 'edit'],
        ['GET', 'admin/settings/notification-deliveries', 'notifications', 'view'],
        ['PUT', 'admin/settings/managed-notifications/*', 'notifications', 'edit'],
        ['POST', 'admin/settings/managed-notifications/*/test-email', 'notifications', 'edit'],
        ['GET', 'admin/notifications', 'notifications', 'view'],
        ['POST', 'admin/notifications/*/read', 'notifications', 'view'],
        ['GET', 'admin/user-logs/*', 'logs', 'view'],
        ['GET', 'admin/salla/app-installations', 'logs', 'view'],
        ['GET', 'admin/webhook-failures*', 'webhook_failures', 'view'], ['POST', 'admin/webhook-failures/*/retry', 'webhook_failures', 'edit'], ['DELETE', 'admin/webhook-failures/*', 'webhook_failures', 'delete'],
    ],
];
