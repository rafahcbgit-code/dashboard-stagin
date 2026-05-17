# Campaign System — All Updates (File by File)

> **From commit:** `7806bd599` (optimization start)  
> **To:** current HEAD  
> **Total:** 1,416 lines added, 68 removed across 12 files

---

## New Files Created

### 1. `app/Jobs/ProcessCampaignChunkJob.php` (615 lines) — NEW

**Before:** Did not exist.  
**After:** Core concurrent-sending job.

**Why it was created:** The old system sent one message at a time per job. This new job sends 50 messages at once using Guzzle Pool with 5 parallel HTTP requests and 100ms delay between each. This is the main optimization that took throughput from ~28 msg/sec to ~50+ msg/sec.

**What it does:**
- Receives 50 log IDs + org credentials + template info
- Bulk-locks logs, marks them `ongoing`
- Builds WhatsApp API payloads for each message
- Sends via Guzzle Pool (5 concurrent, 100ms throttled)
- Processes results: creates Chat/ChatLog for success, stores error for failures
- Broadcasts real-time progress via Pusher
- Dispatches RetryCampaignLogJob for failed messages
- Checks if campaign should be marked `completed`

**Bug fixes applied after creation:**
- `getMediaIdFromCampaign()` was private → made public (commit `3d19d50a2`)
- `_flow_stubs` not stripped before sending → fixed by other dev (commit `294f95c20`)
- Credential leakage: single static WhatsappService → cached per org (commit `294f95c20`)
- Debug logging added for template errors (commit `4e61d0955`)
- Credential audit logging added (commit `58917c6fe`)
- FLOW template comparison test added (commit `cf1532e56`)
- **HTTP 200 with error body bug:** `fulfilled` callback now checks `body->error` before setting `success = true` (commit `e194fe6a5`)

---

### 2. `app/Console/Commands/CampaignConfigCommand.php` (252 lines) — NEW

**Before:** Did not exist.  
**After:** Artisan command for per-org campaign settings.

**Why it was created:** Different organizations have different WhatsApp rate limits. Some can send fast (1000 msg/s), others are slow (20 msg/s). The env vars set global defaults, but org-specific overrides were needed without exposing settings in the frontend.

**What it does:**
- `php artisan campaign:config <org> --show` — view current settings
- `php artisan campaign:config <org> --concurrency=5 --delay=100` — set per-org overrides
- `php artisan campaign:config <org> --reset` — remove overrides, revert to env/defaults
- Validates ranges, shows preview, requires confirmation, invalidates cache

---

## Modified Files

### 3. `app/Services/WhatsappService.php` (2951 → 3104 lines, +153)

**Before:** Created a new Guzzle `Client()` on every API call. No shared client. No accessors for URL/headers/credentials. `sendTemplateMessage()` always queried DB for Contact/Campaign/Template. `buildCampaignTemplateChatMessage` and `buildCarouselTemplateChatMessage` were private. No `buildTemplateRequestPayload()`. No `createChatLogFromResponse()`. `getMediaIdFromCampaign()` was private.

**After:**

| Change | Reason |
|--------|--------|
| Added `static $sharedClient` + `getSharedClient()` + `resetSharedClient()` | TCP connection reuse — avoids creating a new HTTP client for every single API call |
| Added accessors: `getApiVersion()`, `getPhoneNumberIdProperty()`, `getAccessToken()`, `getOrganizationId()`, `getSendUrl()` | ProcessCampaignChunkJob needs URL, headers, and credentials to build Guzzle Pool requests directly |
| Changed `sendHttpRequest()` from `new Client()` to `self::getSharedClient()` | Connection reuse across all requests in a worker process |
| Extended `sendTemplateMessage()` with optional `?Contact`, `?Campaign`, `?Template` params | ProcessCampaignChunkJob pre-loads models — passing them avoids redundant DB queries |
| Made `buildCampaignTemplateChatMessage` public + `?Contact $contact` param | ChunkJob needs to build chat metadata without re-querying the contact |
| Made `buildCarouselTemplateChatMessage` public + `?Template $template` param | Same — avoid re-querying template |
| Added `buildTemplateRequestPayload()` | ChunkJob needs the raw API payload (messaging_product, to, type, template) to send via Guzzle Pool |
| Added `createChatLogFromResponse()` | Extracted from `sendTemplateMessage()` — ChunkJob creates Chat/ChatLog after a successful Pool response without calling `sendTemplateMessage()` |
| Made `getMediaIdFromCampaign()` public | ChunkJob needs to call it (was private, caused runtime error) |
| **Added `body->error` check in `sendHttpRequest()`** | Meta returns HTTP 200 with error in body for rate limits. Now checks for `error` key before setting `success = true` |

---

### 4. `app/Jobs/ProcessCampaignMessagesJob.php` (270 → 311 lines, +41)

**Before:** Dispatched one `ProcessSingleCampaignLogJob` per message via `Bus::batch()`. Used `Bus` facade. No per-org config overrides. No `template_id` in eager load.

**After:**

| Change | Reason |
|--------|--------|
| Replaced `Bus::batch(ProcessSingleCampaignLogJob)` with `ProcessCampaignChunkJob::dispatch()` per chunk | One job per 50 messages instead of one job per message — far less queue overhead |
| Removed `use Illuminate\Support\Facades\Bus` | No longer using Bus::batch |
| Added `template_id` to campaign eager-load select | ChunkJob needs template_id — avoids extra query |
| Added `getChunkSize()`, `getConcurrency()`, `getRequestDelayMs()` methods | Per-org override support: org metadata > env > config default |
| Added `$orgMetadata` to `chunk()` closure `use()` clause | Bug fix — `$orgMetadata` was undefined inside the closure (commit `183abb83a`) |
| Added `requestDelayMs` param to `ProcessCampaignChunkJob::dispatch()` | Passes throttle setting to each chunk job |

---

### 5. `app/Jobs/ProcessSingleCampaignLogJob.php` (430 → 481 lines, +51)

**Before:** Constructor took `CampaignLog $campaignLog` model. Queried Campaign and Template on every `handle()`. No static caching.

**After:**

| Change | Reason |
|--------|--------|
| Constructor changed from `CampaignLog $campaignLog` to scalar params: `int $campaignLogId, int $organizationId, int $campaignId, int $createdBy, int $templateId, int $contactId, string $contactUuid` | Models cannot be serialized in queue jobs reliably. Scalar params are safer and avoid model re-hydration issues. **Breaking change — requires queue drain before deploy.** |
| Added static `$campaignCache` and `$templateCache` with `getCampaignCached()` / `getTemplateCached()` | Same campaign/template is used for all 50 messages in a chunk. Caching avoids 50 identical queries. |
| Passes pre-loaded models to `buildTemplateRequest()` and `sendTemplateMessage()` | Skips redundant DB lookups inside those methods |
| `broadcastProgress()` / `checkAndUpdateCampaignStatus()` use cached campaign | Avoids re-querying campaign for status checks |

---

### 6. `app/Jobs/RetryCampaignLogJob.php` (251 → 255 lines, +4)

**Before:** Eager-loaded `campaign` and `contact` but not `campaign.template`. Called `buildTemplateRequest()` and `sendTemplateMessage()` without pre-loaded models.

**After:**

| Change | Reason |
|--------|--------|
| Added `campaign.template` to eager-load | Avoids extra query inside `buildTemplateRequest()` |
| Passes `$log->campaign` and `$log->campaign->template` to `buildTemplateRequest()` | Uses pre-loaded models instead of re-querying |
| Passes `$log->contact`, `$log->campaign`, `$log->campaign->template` to `sendTemplateMessage()` | Same — avoids 3 extra queries per retry |

---

### 7. `app/Traits/TemplateTrait.php` (479 → 479 lines, +10/-10)

**Before:** `buildTemplateRequest($campaignId, $contact)` always queried DB for Campaign and Template. `buildCarouselTemplateRequest($templateId, $contact)` always queried DB for Template.

**After:**

| Change | Reason |
|--------|--------|
| `buildTemplateRequest($campaignId, $contact, ?Campaign $campaign = null, ?Template $campaignTemplate = null)` | Optional pre-loaded models. If passed, skips DB query. If not passed, queries DB as before (backward compatible). |
| `buildCarouselTemplateRequest(int $templateId, object $contact, ?Template $template = null)` | Same pattern — optional pre-loaded Template |

---

### 8. `config/campaigns.php` (60 → 99 lines, +39)

**Before:** Had `batch_size`, `max_batch_size`, and `retry` config only.

**After:**

| Change | Reason |
|--------|--------|
| Added `chunk_size` (default 50) | Messages per ProcessCampaignChunkJob |
| Added `concurrency` (default 10) | Parallel HTTP requests per chunk job |
| Added `request_delay_ms` (default 100) | Milliseconds pause between each API request — prevents 130429 rate limit errors |
| Added `load_test_mode` (default false) | Skip Meta API calls for benchmarking |

---

### 9. `app/Http/Controllers/User/CampaignController.php` (470 → 545 lines, +75)

**Before:** No `resendFailed()` method. No `rate_limited_failed_count` in view data.

**After:**

| Change | Reason |
|--------|--------|
| Added `rate_limited_failed_count` to campaign view data | Count of rate-limited failures (error code 130429) — drives button visibility in frontend |
| Added `resendFailed(string $uuid)` method | POST /campaigns/{uuid}/resend-failed — resets rate-limited logs to pending, sets campaign ongoing |
| Query includes `status = 'success' AND chat_id IS NULL` with error 130429 | Catches incorrectly-marked-success logs from the HTTP 200 error body bug |

---

### 10. `resources/js/Pages/User/Campaign/View.vue` (452 → 542 lines, +90)

**Before:** Only had "Cancel Campaign" button. No resend functionality.

**After:**

| Change | Reason |
|--------|--------|
| Added "Resend Rate-Limited" button | Only visible when campaign is completed/cancelled AND has rate-limited failures |
| Added `canResendFailed` computed property | Checks `campaign.rate_limited_failed_count > 0` |
| Added confirmation dialog | Same pattern as cancel dialog — amber styling, explains only rate-limited messages are retried |
| Added `resendFailed()` async function | POSTs to `/campaigns/{uuid}/resend-failed`, reloads page on success |

---

### 11. `routes/web.php` (531 → 532 lines, +1)

**Before:** No resend-failed route.

**After:**

| Change | Reason |
|--------|--------|
| Added `POST /campaigns/{uuid}/resend-failed` → `CampaignController@resendFailed` | API endpoint for the resend button |

---

### 12. `app/Services/TriggerEvaluationService.php` (minor change, +28/-7)

**Before/After:** Minor adjustments unrelated to the core optimization. Trigger evaluation logic changes.

---

## Bug Fix Timeline

| Commit | Bug | Fix |
|--------|-----|-----|
| `3d19d50a2` | `getMediaIdFromCampaign()` private → ChunkJob can't call it | Made public |
| `294f95c20` | `_flow_stubs` not stripped before Meta API + credential leakage across orgs | Other dev fixed: strip `_flow_stubs`, cache WhatsappService per org |
| `183abb83a` | `$orgMetadata` undefined inside `chunk()` closure | Added `$orgMetadata` to `use()` clause |
| `e194fe6a5` | Meta returns HTTP 200 with error body → messages marked `success` incorrectly | Check `body->error` in fulfilled callback and `sendHttpRequest()` |

---

## Settings Added (env/config)

```env
CAMPAIGN_CHUNK_SIZE=50          # NEW — messages per chunk job
CAMPAIGN_CONCURRENCY=5         # NEW — parallel HTTP requests
CAMPAIGN_REQUEST_DELAY_MS=100  # NEW — ms between requests
CAMPAIGN_LOAD_TEST_MODE=false  # NEW — skip API for benchmarking
```