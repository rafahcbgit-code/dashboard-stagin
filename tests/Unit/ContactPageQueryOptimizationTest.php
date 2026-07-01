<?php

it('keeps the contacts page away from known slow query patterns', function () {
    $controller = file_get_contents(base_path('app/Http/Controllers/User/ContactController.php'));
    $groupModel = file_get_contents(base_path('app/Models/ContactGroup.php'));
    $resource = file_get_contents(base_path('app/Http/Resources/ContactResource.php'));
    $cacheHelper = file_get_contents(base_path('app/Support/ContactPageCache.php'));

    expect($controller)
        ->toContain('cachedContactCount')
        ->toContain('cachedContactGroups')
        ->toContain('cachedLabels')
        ->toContain('ContactPageCache::contactGroupsKey')
        ->toContain('ContactPageCache::labelsKey')
        ->toContain("->select('chats.id', 'chats.contact_id', 'chats.created_at')")
        ->toContain("->get(['id', 'uuid', 'name', 'contacts_count'])")
        ->toContain("DB::table('chats')")
        ->not->toContain("withCount(['contacts as contacts_count'")
        ->not->toContain('withCount("contacts as contacts_count"')
        ->not->toContain('lastInboundChat:id,contact_id,created_at')
        ->not->toContain("'lastInboundChat' => function (Builder \$query)");

    expect($cacheHelper)
        ->toContain('contacts_page_contact_groups')
        ->toContain('contacts_page_labels');

    expect($groupModel)
        ->toContain('contacts_count as contact_count')
        ->not->toContain("withCount(['contacts as contact_count'");

    expect($resource)
        ->toContain('$this->unread_messages_count ??')
        ->toContain("->where('is_read', 0)");
});

it('defines covering indexes for contacts page list, groups, and unread counts', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_07_01_000002_add_contacts_page_performance_indexes.php'));

    expect($migration)
        ->toContain('idx_contacts_org_deleted_favorite_created')
        ->toContain('`organization_id`, `deleted_at`, `is_favorite`, `created_at`, `id`')
        ->toContain('idx_contact_groups_org_deleted_name')
        ->toContain('`organization_id`, `deleted_at`, `name`, `id`')
        ->toContain('idx_chats_contact_type_deleted_created')
        ->toContain('`contact_id`, `type`, `deleted_at`, `created_at`')
        ->toContain('idx_chats_contact_type_read_deleted')
        ->toContain('idx_chats_unread_opt')
        ->toContain('`contact_id`, `type`, `is_read`, `deleted_at`');
});
