# Laravel Codebase Optimization Audit Report

**Date:** October 26, 2025
**Auditor:** Senior Laravel Developer & DBA

This report breaks down optimization opportunities by module. For each issue, you will find the **Problem**, the **Severity**, the **Fix** (Query/Code), and if applicable, the **Migration** to needed to add indexes.

---

## Module 1: Chat & History

### Issue 1.1: Critical N+1 in Chat History

**Severity:** 🔴 **Critical**
**Location:** `app/Models/ChatLog.php` (Accessor: `getRelatedEntitiesAttribute`)

**Problem:**
When fetching chat history, the `getRelatedEntitiesAttribute` accessor is automatically called. Inside this accessor, a _new_ database query (`Chat::find($id)` or `ChatTicketLog::find($id)`) is executed for **every single log entry**.

- 100 messages = 101 queries (1 for list + 100 for related entities).

**Fix Strategy:**
Stop using the Accessor for this. Instead, using **Eager Loading** with a `morphTo` relationship in the Service layer.

**Step 1: Update Model (`app/Models/ChatLog.php`)**
Add a proper polymorphic relationship and remove the logic from the accessor (or deprecate it).

```php
class ChatLog extends Model {
    // ... existing code ...

    // 1. Add this relationship
    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }
}
```

**Step 2: Update Service (`app/Services/ChatService.php`)**
Refactor the query to eager load the `entity` relationship.

```php
// In getChatMessages() method
$chatLogs = ChatLog::with('entity') // <--- Eager Load here
    ->where('contact_id', $contactId)
    ->whereNull('deleted_at')
    ->orderBy('created_at', 'desc')
    ->paginate($perPage);

// The 'entity' data is now available without extra queries:
foreach ($chatLogs as $log) {
   $data = $log->entity; // No DB Query, already loaded
}
```

---

### Issue 1.2: Slow Unread Count Queries

**Severity:** 🟠 **High**
**Location:** Database / `chats` table

**Problem:**
The application frequently counts unread messages for contacts (e.g., in `ContactResource`). The query looks like:
`SELECT count(*) FROM chats WHERE contact_id = ? AND type = 'inbound' AND is_read = 0 AND deleted_at IS NULL`
There is no index covering `is_read` or `type`, causing the database to scan all chat records for that contact.

**Fix Strategy:**
Add a composite index to the `chats` table.

**Migration Code:**
Run `php artisan make:migration add_unread_index_to_chats_table` and add:

```php
public function up()
{
    Schema::table('chats', function (Blueprint $table) {
        // Compound index for fast specific lookups
        $table->index(['contact_id', 'type', 'is_read', 'deleted_at'], 'idx_chats_unread_opt');
    });
}

public function down()
{
    Schema::table('chats', function (Blueprint $table) {
        $table->dropIndex('idx_chats_unread_opt');
    });
}
```

---

## Module 2: Contacts

### Issue 2.1: N+1 Query in Contact List

**Severity:** 🟠 **High**
**Location:** `app/Http/Resources/ContactResource.php`

**Problem:**
For every contact in a list of 50 contacts, the `toArray` method runs a separate `count(*)` query to get `unread_messages`.

- 50 contacts = 51 queries.

**Fix Strategy:**
Use Laravel's `withCount` in the main query, so the database calculates usage in one go.

**Step 1: Update Service (`app/Services/ContactService.php` or `ChatService.php`)**

```php
// When querying contacts
$contacts = Contact::where('organization_id', $orgId)
    ->withCount(['chats as unread_messages_count' => function ($query) {
        $query->where('type', 'inbound')
              ->where('is_read', 0)
              ->whereNull('deleted_at');
    }])
    ->paginate(20);
```

**Step 2: Update Resource (`app/Http/Resources/ContactResource.php`)**

```php
public function toArray($request)
{
    return [
        // ... other fields
        // Use the pre-loaded attribute if it exists, query only if missing
        'unread_messages' => $this->unread_messages_count ?? $this->chats()->where(...)->count(),
    ];
}
```

---

## Module 3: Webhooks & Integration

### Issue 3.1: Missing Index on Organization Identifier

**Severity:** 🔴 **Critical**
**Location:** `app/Http/Controllers/WebhookController.php`

**Problem:**
Every single webhook request (Message, Status Update) queries the `organizations` table by `identifier`.
`Organization::where('identifier', $identifier)->first()`
This column is NOT indexed, leading to full table scans. As users grow, this will kill response times.

**Fix Strategy:**
Add a unique index to `identifier`.

**Migration Code:**
Run `php artisan make:migration add_identifier_index_to_organizations_table` and add:

```php
public function up()
{
    Schema::table('organizations', function (Blueprint $table) {
        $table->unique('identifier', 'idx_org_identifier');
        // Use 'index' instead of 'unique' if identifiers aren't strictly unique system-wide
    });
}

public function down()
{
    Schema::table('organizations', function (Blueprint $table) {
        $table->dropIndex('idx_org_identifier');
    });
}
```

### Issue 3.2: Redundant Write-Then-Read

**Severity:** 🟡 **Medium**
**Location:** `app/Services/WhatsappService.php`

**Problem:**
The code creates a `Chat` record and immediately executes a query to fetch it back, just to get relationships that are often empty or already known.

```php
$chat = Chat::create([...]);
$chat = Chat::with(...)->find($chat->id); // <--- Redundant Query
```

**Fix Strategy:**
Avoid the second query. Use `setRelation` to manually hydrate the object since you already have the data.

```php
$chat = Chat::create($data);
// Manually attach the contact object you already have in memory
$chat->setRelation('contact', $contact);
// $chat is now ready to return
```

---

## Module 4: Campaigns

### Issue 4.1: Idempotency & Duplicate Checks

**Severity:** 🟡 **Medium**
**Location:** `app/Jobs/Webhook/ProcessWebhookJob.php`

**Problem:**
The job checks `Chat::where('organization_id', ...)->where('wam_id', ...)->exists()` to prevent duplicates. Without a compound index, this is slower than necessary.

**Fix Strategy:**
Add a composite index for these two columns.

**Migration Code:**
Run `php artisan make:migration add_wam_id_index_to_chats` and add:

```php
public function up()
{
    Schema::table('chats', function (Blueprint $table) {
        $table->index(['organization_id', 'wam_id'], 'idx_chats_dedupe');
    });
}

public function down()
{
    Schema::table('chats', function (Blueprint $table) {
        $table->dropIndex('idx_chats_dedupe');
    });
}
```

### Issue 4.2: Bulk Insertions

**Severity:** 🟢 **Low (Optimization)**
**Location:** `app/Services/CampaignService.php`

**Problem:**
When processing a campaign for 1,000 users, looping and doing `Chat::create()` 1,000 times is slow.

**Fix Strategy:**
Use `Chat::insert($array)` for chunks of data.

```php
$batchData = [];
foreach ($contacts as $contact) {
    $batchData[] = [
        'organization_id' => $orgId,
        'contact_id' => $contact->id,
        'created_at' => now(),
        // ...
    ];
}
// Insert 500 records at a time
foreach (array_chunk($batchData, 500) as $chunk) {
    Chat::insert($chunk);
}
```

---

## Summary of Immediate Actions

1.  **Run Migrations:** Create the 3 migration files above (Organization Identifier, Chat Unread Index, Chat Dedupe Index).
2.  **Refactor ChatLog:** Implement `morphTo` relationship to solve the Critical N+1.
3.  **Refactor ContactService:** Add `withCount` to the contact listing query.
