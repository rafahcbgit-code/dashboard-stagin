# Campaign Message Processing Optimization — Implementation Guide

## Overview

This optimization reduces campaign message processing time by ~4.7x through two changes:

1. **Concurrent HTTP sending** — new `ProcessCampaignChunkJob` sends multiple messages in parallel per worker using Guzzle Pool instead of one-at-a-time
2. **DB query reduction** — eliminates redundant queries by passing pre-loaded models through the pipeline instead of re-fetching the same data

**Before:** 200k messages in ~2 hours (28 msg/sec)
**After:** 200k messages in ~27 minutes (122 msg/sec) — scalable to ~10 min with higher concurrency

---

## Files to Create (1)

### 1. `app/Jobs/ProcessCampaignChunkJob.php` (NEW)

This is the core of the optimization. It processes a chunk of 50 messages per job, sending them concurrently via Guzzle Pool.

**Key responsibilities:**
- Bulk-locks all campaign logs in one query
- Bulk-loads contacts in one query
- Caches Campaign and Template in static properties (reused across chunks in same worker)
- Sends messages concurrently (configurable concurrency via `CAMPAIGN_CONCURRENCY` env)
- Batch-updates log statuses after all sends complete
- Broadcasts progress once per chunk (every 50 messages) instead of per-message
- Checks campaign completion once per chunk

**Constructor signature:**
```php
public function __construct(
    array $logIds,          // array of campaign_log IDs
    int $organizationId,
    int $campaignId,
    int $templateId,
    int $createdBy,
    int $concurrency = 10,  // defaults to config('campaigns.concurrency', 10)
)
```

**Flow:**
1. Lock all logs in one query (`lockForUpdate`)
2. Check campaign not cancelled (static-cached)
3. Bulk-load contacts (`Contact::whereIn`)
4. Load template (static-cached)
5. Initialize WhatsappService once
6. Mark all logs as 'ongoing' (1 bulk update)
7. Build template payloads (uses pre-loaded Campaign, Template, Contact)
8. Send concurrently via `GuzzleHttp\Pool` with configurable concurrency
9. Process results — create Chat/ChatLog records per success, batch-update statuses
10. Broadcast progress once
11. Check campaign completion once

**Important methods to implement:**
- `handle()` — main entry point
- `sendConcurrently()` — Guzzle Pool with `fulfilled`/`rejected` callbacks
- `processResults()` — creates Chat/ChatLog records, batch-updates CampaignLog statuses
- `initializeWhatsappService()` — creates WhatsappService with cached org metadata
- `getCampaignCached()` / `getTemplateCached()` — static property caches
- `broadcastProgressIfNeeded()` — throttled progress broadcast (every 10 messages or 5 seconds)
- `checkAndUpdateCampaignStatus()` — marks campaign completed + schedules retries

**Guzzle Pool implementation:**
```php
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

$client = WhatsappService::getSharedClient(); // Reuses TCP connections
$url = $this->whatsappService->getSendUrl();
$headers = $this->whatsappService->setHeaders();

$requests = function () use ($messages, $url, $headers) {
    foreach ($messages as $index => $msg) {
        yield $index => new Request('POST', $url, $headers, json_encode($msg['payload']));
    }
};

$pool = new Pool($client, $requests(), [
    'concurrency' => $this->concurrency,
    'fulfilled' => function ($response, $index) use (&$results, $messages) {
        // Parse response, store result
    },
    'rejected' => function ($reason, $index) use (&$results, $messages) {
        // Handle error, store failure result
    },
]);

$pool->promise()->wait();
```

**Result processing (per-message on success):**
- Create `Chat` record
- Create `ChatLog` record
- Dispatch `NewChatEvent`
- Handle MetaFlow stubs (create `MetaFlowResponse` records)
- Trigger `WebhookHelper::triggerWebhookEvent('message.sent', ...)`

**Load test mode:** If `config('app.load_test_mode', false)` is true, skip Meta API calls and return fake success responses.

---

## Files to Modify (6)

### 2. `app/Traits/TemplateTrait.php`

**Change:** Add optional pre-loaded model parameters to avoid redundant DB queries.

**`buildTemplateRequest` — line ~14:**
```php
// BEFORE:
function buildTemplateRequest($campaignId, $contact)
{
    $campaign = Campaign::where('id', $campaignId)->first();
    $campaignTemplate = Template::where('id', $campaign->template_id)->first();

// AFTER:
function buildTemplateRequest($campaignId, $contact, ?Campaign $campaign = null, ?Template $campaignTemplate = null)
{
    $campaign = $campaign ?? Campaign::where('id', $campaignId)->first();
    $campaignTemplate = $campaignTemplate ?? Template::where('id', $campaign->template_id)->first();
```

**`buildCarouselTemplateRequest` — line ~383:**
```php
// BEFORE:
function buildCarouselTemplateRequest(int $templateId, object $contact): array
{
    \Log::info('buildCarouselTemplateRequest');
    $template = Template::where('id', $templateId)->first();

// AFTER:
function buildCarouselTemplateRequest(int $templateId, object $contact, ?Template $template = null): array
{
    \Log::info('buildCarouselTemplateRequest');
    $template = $template ?? Template::where('id', $templateId)->first();
```

**Impact:** When callers pass pre-loaded models, 2-3 DB queries per message are eliminated. Backward-compatible — existing callers that pass IDs still work.

---

### 3. `app/Services/WhatsappService.php`

**Changes:**

#### 3a. Add shared Guzzle client and accessors (after `$wabaId` property declaration):

```php
private static ?Client $sharedClient = null;

public static function getSharedClient(): Client
{
    if (self::$sharedClient === null) {
        self::$sharedClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }
    return self::$sharedClient;
}

public function getApiVersion(): string
{
    return $this->apiVersion;
}

public function getPhoneNumberIdProperty(): string
{
    return $this->phoneNumberId;
}

public function getAccessToken(): string
{
    return $this->accessToken;
}
```

> **Note:** Use `getPhoneNumberIdProperty()` not `getPhoneNumberId()` — there's already an existing `getPhoneNumberId()` method at line ~2739 that fetches phone number info from the Meta API.

#### 3b. Update `sendHttpRequest` to use shared client:

```php
// BEFORE:
private function sendHttpRequest($method, $url, $data = [], $headers = [])
{
    $client = new Client();

// AFTER:
private function sendHttpRequest($method, $url, $data = [], $headers = [])
{
    $client = self::getSharedClient();
```

#### 3c. Add optional model params to `sendTemplateMessage`:

```php
// BEFORE:
public function sendTemplateMessage($contactUuId, $templateContent, $userId = NULL, $campaignId = NULL, $mediaId = NULL)

// AFTER:
public function sendTemplateMessage($contactUuId, $templateContent, $userId = NULL, $campaignId = NULL, $mediaId = NULL, ?Contact $contact = null, ?Campaign $campaign = null, ?Template $template = null)
```

Inside the method, change:
```php
// BEFORE:
$contact = Contact::where('uuid', $contactUuId)->first();

// AFTER:
$contact = $contact ?? Contact::where('uuid', $contactUuId)->first();
```

And for the campaign/template queries inside `sendTemplateMessage`:
```php
// BEFORE:
$campaign = Campaign::find($campaignId);
$campaignMetadata = json_decode($campaign->metadata);
$template = Template::find($campaign->template_id);

// AFTER:
$campaign = $campaign ?? Campaign::find($campaignId);
$campaignMetadata = json_decode($campaign->metadata);
$template = $template ?? Template::find($campaign->template_id);
```

#### 3d. Add `getSendUrl()` method:

```php
public function getSendUrl(): string
{
    return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
}
```

#### 3e. Add `buildTemplateRequestPayload()` method:

```php
public function buildTemplateRequestPayload(Contact $contact, array $templateContent, ?int $campaignId = null): array
{
    $requestData = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $contact->phone,
        'type' => 'template',
        'template' => $templateContent,
    ];

    if ($campaignId !== null) {
        $requestData['template']['_campaign_id'] = $campaignId;
    }

    return $requestData;
}
```

#### 3f. Add `createChatLogFromResponse()` method:

This method extracts the Chat/ChatLog creation logic from `sendTemplateMessage` so it can be called from `ProcessCampaignChunkJob`. It creates Chat and ChatLog records, dispatches NewChatEvent, handles MetaFlow stubs, and triggers webhooks.

#### 3g. Make `buildCampaignTemplateChatMessage` and `buildCarouselTemplateChatMessage` public:

```php
// BEFORE:
private function buildCampaignTemplateChatMessage($templateMetadata, $contactUuId){

// AFTER:
public function buildCampaignTemplateChatMessage($templateMetadata, $contactUuId, ?Contact $contact = null){
    $contact = $contact ?? Contact::where('uuid', $contactUuId)->first();
```

```php
// BEFORE:
private function buildCarouselTemplateChatMessage(string $contactUuid, int $templateId): string
{
    $template = Template::where('id', $templateId)->first();

// AFTER:
public function buildCarouselTemplateChatMessage(string $contactUuid, int $templateId, ?Template $template = null): string
{
    $template = $template ?? Template::where('id', $templateId)->first();
```

---

### 4. `app/Jobs/ProcessCampaignMessagesJob.php`

**Change:** Dispatch `ProcessCampaignChunkJob` chunks instead of individual `ProcessSingleCampaignLogJob` instances.

**Key changes in the `handle()` method's chunk callback:**

```php
// BEFORE:
$jobs = [];
foreach ($logs as $log) {
    // ... filter logic ...
    $jobs[] = new ProcessSingleCampaignLogJob($log);
}
if (! empty($jobs)) {
    Bus::batch($jobs)->allowFailures()->onQueue($this->queue)->dispatch();
}

// AFTER:
$eligibleLogs = [];
foreach ($logs as $log) {
    // ... same filter logic ...
    $eligibleLogs[] = $log;
}
if (! empty($eligibleLogs)) {
    $logsByCampaign = collect($eligibleLogs)->groupBy('campaign_id');
    $chunkSize = config('campaigns.chunk_size', 50);

    foreach ($logsByCampaign as $campaignId => $campaignLogs) {
        $campaign = $campaignLogs->first()->campaign;
        if (! $campaign || $campaign->status === 'cancelled') continue;

        foreach ($campaignLogs->chunk($chunkSize) as $chunk) {
            $job = new ProcessCampaignChunkJob(
                logIds: $chunk->pluck('id')->toArray(),
                organizationId: $this->organizationId,
                campaignId: $campaignId,
                templateId: $campaign->template_id,
                createdBy: $campaign->created_by,
            );
            $job->onQueue($this->queue);
            dispatch($job);
        }
    }
}
```

**Also update the campaign eager-load to include `template_id`:**
```php
// BEFORE:
'campaign' => fn($q) => $q->select(['id', 'organization_id', 'status', 'uuid', 'created_by']),

// AFTER:
'campaign' => fn($q) => $q->select(['id', 'organization_id', 'status', 'uuid', 'created_by', 'template_id']),
```

**Add import:**
```php
use App\Jobs\ProcessCampaignChunkJob;
```

**Remove import (no longer needed):**
```php
use Illuminate\Support\Facades\Bus;  // Remove this
```

---

### 5. `app/Jobs/ProcessSingleCampaignLogJob.php`

**Change:** Replace `CampaignLog` model constructor with scalar params. Add static caches. Pass pre-loaded models.

**New constructor:**
```php
// BEFORE:
public function __construct(CampaignLog $campaignLog)
{
    $this->campaignLog = $campaignLog;
}

// AFTER:
public function __construct(
    int $campaignLogId,
    int $organizationId,
    int $campaignId,
    int $createdBy,
    int $templateId,
    int $contactId,
    string $contactUuid,
)
{
    $this->queue = 'campaign-messages';
    $this->campaignLogId = $campaignLogId;
    $this->organizationId = $organizationId;
    $this->campaignId = $campaignId;
    $this->createdBy = $createdBy;
    $this->templateId = $templateId;
    $this->contactId = $contactId;
    $this->contactUuid = $contactUuid;
}
```

**Add static caches:**
```php
protected static array $campaignCache = [];
protected static array $templateCache = [];

private function getCampaignCached(int $campaignId): ?Campaign
{
    return self::$campaignCache[$campaignId] ??= Campaign::find($campaignId);
}

private function getTemplateCached(int $templateId): ?Template
{
    return self::$templateCache[$templateId] ??= Template::find($templateId);
}
```

**Update `handle()` method:**
- Replace `$this->campaignLog->id` → `$this->campaignLogId`
- Replace `$this->campaignLog->campaign_id` → `$this->campaignId`
- Replace `$this->campaignLog->contact->uuid` → `$this->contactUuid`
- Replace `Campaign::find()` → `$this->getCampaignCached()`
- Replace `Template::find()` → `$this->getTemplateCached()`
- Load Contact once: `$contact = Contact::find($this->contactId)`
- Pass pre-loaded models to trait methods:
  - `buildTemplateRequest($this->campaignId, $contact, $campaign, $template)`
  - `buildCarouselTemplateRequest($this->templateId, $contact, $template)`
- Pass pre-loaded models to WhatsappService:
  - `sendTemplateMessage($this->contactUuid, $payload, $this->createdBy, $this->campaignId, null, $contact, $campaign, $template)`

**Update `broadcastProgress()` and `checkAndUpdateCampaignStatus()` to use `$this->getCampaignCached()`:**
```php
// BEFORE:
$campaign = Campaign::find($campaignId);

// AFTER:
$campaign = $this->getCampaignCached($campaignId);
```

**Add `use App\Models\Contact;` and `use App\Models\Template;` imports.**

**Update `resetProgressState()` to also clear caches:**
```php
public static function resetProgressState(int $campaignId = null): void
{
    if ($campaignId === null) {
        self::$progressState = [];
        self::$campaignCache = [];
        self::$templateCache = [];
    } else {
        unset(self::$progressState[$campaignId]);
        unset(self::$campaignCache[$campaignId]);
    }
}
```

---

### 6. `app/Jobs/RetryCampaignLogJob.php`

**Change:** Pass pre-loaded models to trait/service methods.

**Update the eager-load to include template:**
```php
// BEFORE:
$log = CampaignLog::with('campaign', 'campaign.organization', 'contact')->find($this->campaignLogId);

// AFTER:
$log = CampaignLog::with('campaign', 'campaign.organization', 'campaign.template', 'contact')->find($this->campaignLogId);
```

**Update the buildTemplateRequest call:**
```php
// BEFORE:
$template = $this->buildTemplateRequest($log->campaign_id, $log->contact);

// AFTER:
$template = $this->buildTemplateRequest($log->campaign_id, $log->contact, $log->campaign, $log->campaign->template ?? null);
```

**Update the sendTemplateMessage call:**
```php
// BEFORE:
$response = $this->whatsappService->sendTemplateMessage(
    $log->contact->uuid,
    $template,
    $campaign_user_id,
    $log->campaign_id
);

// AFTER:
$response = $this->whatsappService->sendTemplateMessage(
    $log->contact->uuid,
    $template,
    $campaign_user_id,
    $log->campaign_id,
    null,
    $log->contact,
    $log->campaign,
    $log->campaign->template ?? null
);
```

---

### 7. `config/campaigns.php`

**Add two new config values:**

```php
// After 'max_batch_size' => env('CAMPAIGN_MAX_BATCH_SIZE', 1000),

/*
|--------------------------------------------------------------------------
| Chunk Size (Concurrent Processing)
|--------------------------------------------------------------------------
|
| Number of messages processed in a single ProcessCampaignChunkJob.
| Each chunk sends messages concurrently via Guzzle Pool.
|
*/
'chunk_size' => env('CAMPAIGN_CHUNK_SIZE', 50),

/*
|--------------------------------------------------------------------------
| Concurrency (HTTP)
|--------------------------------------------------------------------------
|
| Number of concurrent HTTP requests per chunk job.
| Higher values = faster throughput, but more API pressure.
| Start with 5-10 and increase if rate limits allow.
|
*/
'concurrency' => env('CAMPAIGN_CONCURRENCY', 10),
```

---

## Environment Variables

Add to `.env`:

```env
CAMPAIGN_CHUNK_SIZE=50
CAMPAIGN_CONCURRENCY=5
```

Start with `CAMPAIGN_CONCURRENCY=5` and increase to 10 if Meta rate limits allow.

---

## Deployment Steps

1. **Drain the campaign-messages queue** — The `ProcessSingleCampaignLogJob` constructor signature changed from `CampaignLog $campaignLog` to scalar params. Any jobs already in the queue with the old format will fail. Either wait for the queue to empty or add a temporary compatibility check.

2. **Deploy the code**

3. **Run `php artisan queue:restart`** — Workers need to pick up the new code. Also clears static caches.

4. **Start with `CAMPAIGN_CONCURRENCY=5`** — Monitor for 429 rate limit errors, then increase.

5. **Monitor** — Watch the `campaign` log channel for:
   - `Campaign chunk processed` entries (new chunk job logs)
   - `Campaign message processed` entries (single-job fallback)
   - Any 429 or rate limit errors
   - Total processing time for campaigns

6. **Verify** — The `ProcessSingleCampaignLogJob` is kept as fallback for retries. New campaigns use `ProcessCampaignChunkJob`.

---

## Verification Checklist

- [ ] `app/Jobs/ProcessCampaignChunkJob.php` exists and all imports resolve
- [ ] `php artisan config:cache` runs without errors
- [ ] All 7 files pass `php -l` syntax check
- [ ] `config('campaigns.chunk_size')` returns 50
- [ ] `config('campaigns.concurrency')` returns 10 (or env override)
- [ ] Campaign-messages queue processes test campaign correctly
- [ ] RetryCampaignLogJob still works for failed messages
- [ ] Progress broadcast events still fire
- [ ] Campaign completion detection still works
- [ ] Carousel templates still send correctly
- [ ] Chat and ChatLog records are created for successful sends
- [ ] MetaFlow stubs are created for FLOW buttons
- [ ] Webhook events are triggered

---

## Query Count Comparison

### Per message (ProcessSingleCampaignLogJob — retry path):

| Query | Before | After |
|-------|--------|-------|
| CampaignLog rehydrate | 1 | 0 (scalar ID) |
| Lock CampaignLog | 1 | 1 |
| Campaign find (cancel check) | 1 | 0 (static cache) |
| Campaign lazy-load | 1 | 0 (constructor param) |
| Template find | 2 | 1 (static cache) |
| Contact find | 2 | 1 (single query) |
| buildTemplateRequest queries | 2 | 0 (passed models) |
| sendTemplateMessage queries | 3 | 0 (passed models) |
| Status updates | 2 | 2 |
| Broadcast/completion Campaign | 2 | 0 (static cache) |
| **Total** | **~16** | **~5** |

### Per chunk of 50 (ProcessCampaignChunkJob — main path):

| Query | Count |
|-------|-------|
| Lock logs (bulk) | 1 |
| Campaign (cached) | 1 (first chunk only) |
| Contacts (bulk) | 1 |
| Template (cached) | 1 (first chunk only) |
| Mark ongoing (bulk) | 1 |
| Batch status update | 1-2 |
| Stats aggregate | 1 |
| Completion check | 1 |
| **Total for 50 messages** | **~8** |
| **Per message** | **~0.16** |