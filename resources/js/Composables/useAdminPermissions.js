import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const pageModules = [
    ['/admin/dashboard', 'dashboard'],
    ['/admin/organizations', 'organizations'],
    ['/admin/users', 'customers'],
    ['/admin/payment-logs', 'billing'],
    ['/admin/billing', 'billing'],
    ['/admin/support', 'support'],
    ['/admin/team/users', 'team'],
    ['/admin/team/roles', 'roles'],
    ['/admin/plans', 'subscription_plans'],
    ['/admin/resource-addons', 'resource_addons'],
    ['/admin/otp-config', 'otp_config'],
    ['/admin/addons', 'addons'],
    ['/admin/languages', 'languages'],
    ['/admin/settings', 'settings'],
    ['/admin/faqs', 'settings'],
    ['/admin/testimonials', 'settings'],
];

export function useAdminPermissions() {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.adminPermissions ?? []);

    const can = (module, action = 'view') => permissions.value.includes('*')
        || permissions.value.includes(`${module}.${action}`);

    const currentModule = computed(() => {
        const url = page.url.split('?')[0];
        return pageModules.find(([prefix]) => url.startsWith(prefix))?.[1] ?? null;
    });

    const canCurrent = (action = 'view') => currentModule.value
        ? can(currentModule.value, action)
        : false;

    return { can, canCurrent, currentModule, permissions };
}
