<?php

return [
    /*
    | Allow a reply to an older Flow Builder menu to restart the flow reached
    | by that option's portal. Keep disabled until the accompanying database
    | migrations have been deployed.
    */
    'stale_portal_reentry_enabled' => env('FLOW_STALE_PORTAL_REENTRY_ENABLED', false),

    // Comma-separated organization IDs for a gradual rollout while the
    // global switch remains disabled.
    'stale_portal_reentry_organizations' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('FLOW_STALE_PORTAL_REENTRY_ORGANIZATIONS', ''))
    ))),

    // Failures are always persisted. Successful attempts are opt-in to avoid
    // unbounded write volume on high-throughput organizations.
    'persist_successful_send_attempts' => env('FLOW_PERSIST_SUCCESSFUL_SEND_ATTEMPTS', false),
];
