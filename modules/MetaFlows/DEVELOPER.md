# MetaFlows — Developer Documentation

## Table of Contents

1. [Module Overview](#1-module-overview)
2. [Directory Structure](#2-directory-structure)
3. [Database Schema](#3-database-schema)
4. [Flow Lifecycle](#4-flow-lifecycle)
5. [Creating & Uploading a Flow](#5-creating--uploading-a-flow)
6. [Publishing, Deprecating & Deleting](#6-publishing-deprecating--deleting)
7. [Syncing from Meta](#7-syncing-from-meta)
8. [Sending a Flow to a Contact](#8-sending-a-flow-to-a-contact)
9. [Receiving Flow Responses (Webhook)](#9-receiving-flow-responses-webhook)
10. [Google Sheets Integration](#10-google-sheets-integration)
11. [Frontend — View.vue](#11-frontend--viewvue)
12. [API Routes Reference](#12-api-routes-reference)
13. [Local Testing Guide](#13-local-testing-guide)
14. [Key Design Decisions](#14-key-design-decisions)

---

## 1. Module Overview

MetaFlows lets agents send **WhatsApp Business Flows** to contacts — structured multi-screen forms that appear natively inside WhatsApp. When a contact fills out and submits a flow, the responses are captured via webhook and optionally auto-written to a linked Google Spreadsheet.

**Core capabilities:**
- Create, upload JSON, publish, deprecate, and delete flows via the Meta Graph API
- Send flows to individual contacts as interactive WhatsApp messages
- Capture submitted responses from the `nfm_reply` webhook event
- Auto-populate a Google Sheet row for every submission (no-code setup via sidebar UI)

**Dependencies:**
- `laravel/socialite ^5.12` — Google OAuth 2.0
- `google/apiclient ^2.0` — (installed but not used directly; Guzzle hits the REST API instead)
- `guzzlehttp/guzzle` — all HTTP calls to Meta Graph API and Google Sheets API

---

## 2. Directory Structure

```
modules/MetaFlows/
├── Controllers/
│   ├── MetaFlowController.php      # All flow CRUD + Google Sheets config endpoints
│   ├── GoogleOAuthController.php   # OAuth connect / callback / disconnect
│   └── SetupController.php         # Admin addon toggle
├── Database/
│   └── Migrations/
│       ├── 2025_02_21_000001_create_meta_flows_table.php
│       ├── 2025_02_21_000002_create_meta_flow_responses_table.php
│       └── 2025_03_01_000003_add_google_sheets_to_meta_flows_table.php
├── Models/
│   ├── MetaFlow.php                # Flow model — includes extractFlowFields()
│   └── MetaFlowResponse.php        # Submitted response model
├── Pages/User/
│   ├── View.vue                    # Flow editor page (builder + Google Sheets sidebar)
│   ├── Index.vue                   # Flow list page
│   └── Responses.vue               # Submitted responses viewer
├── Resources/
│   └── MetaFlowResource.php        # API resource transform
├── Services/
│   ├── MetaFlowService.php         # All Meta Graph API logic
│   └── GoogleSheetsService.php     # Google Sheets API logic + token refresh
├── Requests/
│   └── StoreMetaFlowRequest.php    # Validation for flow creation
└── routes.php                      # All module routes

app/Jobs/
└── PopulateGoogleSheetJob.php      # Queued job: writes one row to the linked sheet
```

---

## 3. Database Schema

### `meta_flows`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | string unique | Public identifier used in URLs |
| `organization_id` | bigint | Owning organization |
| `meta_flow_id` | string nullable | ID assigned by Meta when the flow is created on their platform |
| `name` | string | Display name |
| `categories` | json | e.g. `["SIGN_UP", "LEAD_GENERATION"]` |
| `endpoint_uri` | string nullable | For data-exchange flows (server-driven) |
| `asset_content` | longtext nullable | The raw Flow JSON (screens + components) |
| `preview_url` | string nullable | Meta-provided preview link |
| `status` | enum | `draft`, `published`, `deprecated`, `blocked`, `throttled` |
| `google_sheet_url` | string nullable | Full Google Sheets URL |
| `google_sheet_tab` | string nullable | Tab/sheet name, default `Sheet1` |
| `google_sheet_mapping` | json nullable | `[{flow_field, sheet_column}, ...]` |
| `google_sheet_enabled` | boolean | Whether auto-population is active |
| `deleted_at` | timestamp | Soft delete |

### `meta_flow_responses`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | string unique | |
| `organization_id` | bigint | |
| `meta_flow_id` | bigint FK | References `meta_flows.id` (cascade delete) |
| `contact_id` | bigint | The contact who submitted |
| `flow_token` | string nullable | UUID generated at send time; used to match webhook response to this record |
| `response_json` | text nullable | Raw JSON string from `nfm_reply`; `null` until the contact submits |

**The Two-Phase Record Pattern:**

A `MetaFlowResponse` row is created **twice** in its lifecycle:

1. **At send time** (`sendFlowToContact`): row created with `response_json = null`. Acts as a stub so the webhook handler knows which flow and contact the incoming response belongs to.
2. **At webhook time** (`handleFlowResponse`): the stub is found by `flow_token`, then `response_json` is filled with the contact's submitted data.

---

## 4. Flow Lifecycle

```
[Create on Meta] → draft
       ↓
[Upload asset_content JSON]
       ↓
[Publish] → published   ←→   [Sync from Meta]
       ↓
[Deprecate] → deprecated
       ↓
[Delete] → soft-deleted locally, deleted on Meta
```

**Status rules enforced by Meta:**
- Only `draft` flows can have their JSON updated
- Only `draft` flows can be published
- Only `published` flows can be deprecated
- Only `draft` or `deprecated` flows can be deleted

---

## 5. Creating & Uploading a Flow

### Creation (`MetaFlowService::createFlow`)

```
POST /automation/meta-flows
  → MetaFlowController::store()
  → MetaFlowService::createFlow($data)
     → POST https://graph.facebook.com/{version}/{wabaId}/flows  (Meta API)
     → MetaFlow::create([...])   (local record, status='draft')
  → redirect to /automation/meta-flows/{uuid}
```

The local record is always created even if the Meta API call fails, so the user has a local reference. The `meta_flow_id` column stores Meta's assigned ID.

### Uploading Flow JSON (`MetaFlowService::updateFlowAsset`)

```
PUT /automation/meta-flows/{uuid}   { asset_content: "..." }
  → MetaFlowController::update()
  → MetaFlowService::updateFlowAsset($flow, $jsonContent)
     → POST https://graph.facebook.com/{version}/{meta_flow_id}/assets
        (multipart/form-data with file=flow.json, asset_type=FLOW_JSON)
     → $flow->update(['asset_content' => $jsonContent])  on success
```

Meta requires the Flow JSON as a **multipart file upload** — not a JSON body. The `makeMultipartRequest()` method handles this. The local `asset_content` is only updated after Meta confirms success so the database never holds a version Meta rejected.

**Why the full JSON content is stored locally:**
- The frontend builder reads from `asset_content` — no extra API call needed on page load
- `syncFlowFromMeta()` can overwrite it with the canonical version from Meta
- `resolveFirstScreenId()` reads the first screen ID from it when sending a flow

---

## 6. Publishing, Deprecating & Deleting

All three follow the same pattern:

```php
// POST {meta_flow_id}/publish | /deprecate | DELETE {meta_flow_id}
$response = $this->makeRequest('POST'|'DELETE', $url);

if (!$response->success) {
    throw new \RuntimeException($response->message);
}

$flow->update(['status' => 'published'|'deprecated']);
```

Failures throw a `RuntimeException` which the controller catches and returns as a 422 JSON response. **The local record is never updated unless Meta confirms the action.**

---

## 7. Syncing from Meta

```
POST /automation/meta-flows/{uuid}/sync
  → MetaFlowService::syncFlowFromMeta($flow)
     → GET {meta_flow_id}?fields=id,name,status,categories,preview,endpoint_uri
        → updates: status, preview_url, categories
     → GET {meta_flow_id}/assets
        → finds the FLOW_JSON asset type
        → GET asset.download_url  (authenticated)
        → updates: asset_content
```

Sync is used when the flow was edited elsewhere (e.g. Meta's Flow Builder UI) and the local copy is out of date. The pull-all-flows endpoint (`POST /pull`) does a lightweight sync of name/status/preview for all flows without fetching assets.

---

## 8. Sending a Flow to a Contact

```
MetaFlowService::sendFlowToContact($flow, $contact, $options)
```

**Step 1 — Resolve the first screen ID**

```php
private function resolveFirstScreenId(MetaFlow $flow, ?string $override): string
```

Meta rejects the send request if the `screen` name in `flow_action_payload` doesn't match what's in the published flow JSON. This method reads `screens[0].id` from `asset_content` to avoid hardcoding screen names. Falls back to `'INTRO'` with a warning if `asset_content` is empty.

**Step 2 — Generate flow token**

```php
$flowToken = Str::uuid()->toString();
```

A unique UUID is generated for each send event. This token is embedded in the message payload and will be returned by Meta inside the user's `nfm_reply` response, allowing the system to match the submission back to the correct flow and contact.

**Step 3 — Build and send the message**

```php
POST https://graph.facebook.com/{version}/{phoneNumberId}/messages
{
  "type": "interactive",
  "interactive": {
    "type": "flow",
    "action": {
      "name": "flow",
      "parameters": {
        "flow_message_version": "3",
        "flow_token": "<uuid>",
        "flow_id": "<meta_flow_id>",
        "flow_cta": "Open",
        "flow_action": "navigate",
        "flow_action_payload": { "screen": "WELCOME" }
      }
    }
  }
}
```

**Step 4 — Pre-create the stub response record**

```php
MetaFlowResponse::create([
    'organization_id' => $this->organizationId,
    'meta_flow_id'    => $flow->id,       // local id, not meta's id
    'contact_id'      => $contact->id,
    'flow_token'      => $flowToken,
    'response_json'   => null,            // filled when the contact submits
]);
```

This stub exists so the webhook handler can find it later by token — even if the contact submits hours after receiving the flow.

---

## 9. Receiving Flow Responses (Webhook)

### Full Call Chain

```
POST /webhook/whatsapp/{identifier}
  ↓
WebhookController::handle()
  → validates HMAC-SHA256 signature (if app_secret is set)
  → dispatches ProcessWebhookJob to queue 'webhook-high'
  → returns HTTP 200 immediately

ProcessWebhookJob::handle()
  → webhookType = 'messages'
  → processMessages()
     → processMessage($message, $contactInfo)
        → if message.type === 'interactive'
           → processMetaFlowResponse($message, $contact->id)

processMetaFlowResponse()
  → if interactive.type !== 'nfm_reply' → return
  → MetaFlowService::fromOrganization($orgId)->handleFlowResponse($message, $contactId)
```

### `handleFlowResponse($message, $contactId)`

```php
// 1. Extract response_json from the nfm_reply payload
$nfmReply     = $message['interactive']['nfm_reply'];
$responseJson = $nfmReply['response_json'];   // This is a JSON STRING (double-encoded)

// 2. Parse the string to extract the flow_token
$parsed    = json_decode($responseJson, true);
$flowToken = $parsed['flow_token'];

// 3. Find the pre-created stub by token
$flowResponse = MetaFlowResponse::where('flow_token', $flowToken)
    ->where('organization_id', $this->organizationId)
    ->first();

// 4. Fill in the submitted data
$flowResponse->update([
    'contact_id'    => $contactId,
    'response_json' => $responseJson,   // store the raw string
]);

// 5. Dispatch Google Sheets job if configured
$flow = MetaFlow::find($flowResponse->meta_flow_id);
if ($flow->google_sheet_enabled && $flow->google_sheet_url) {
    PopulateGoogleSheetJob::dispatch($flowResponse->id, $this->organizationId);
}
```

**Why `response_json` is a double-encoded string:**
Meta sends `nfm_reply.response_json` as an already-stringified JSON — i.e. it's a string containing JSON, not a JSON object. The system stores it as-is and `json_decode`s it when needed.

---

## 10. Google Sheets Integration

### 10.1 Architecture Overview

```
Organization has one Google account connected (stored in metadata.google_oauth)
Each MetaFlow can link to one Google Sheet tab
When a flow response arrives → PopulateGoogleSheetJob appends one row
```

**Token storage** — all Google credentials live in `organizations.metadata` JSON column under the `google_oauth` key:

```json
{
  "whatsapp": { ... },
  "google_oauth": {
    "access_token":     "ya29.xxx",
    "refresh_token":    "1//xxx",
    "google_email":     "user@gmail.com",
    "connected_at":     "2026-02-25T00:00:00+00:00",
    "token_expires_at": "2026-02-25T01:00:00+00:00"
  }
}
```

This mirrors the existing pattern used for WhatsApp credentials — no separate OAuth tokens table needed.

---

### 10.2 OAuth Flow (`GoogleOAuthController`)

#### `connect($request)`

```
GET /automation/meta-flows/google/connect?flow_uuid={uuid}
```

1. Reads `current_organization` from session
2. Encodes `{flow_uuid, org_id}` as base64 JSON — this becomes the OAuth `state` parameter
3. Saves state to session for CSRF validation
4. Redirects to Google consent screen via Socialite with:
   - Scope: `https://www.googleapis.com/auth/spreadsheets`
   - `access_type=offline` — requests a refresh token
   - `prompt=consent` — forces re-consent even if previously authorized (needed to always get a refresh token)

#### `callback($request)`

```
GET /automation/meta-flows/google/callback?code=...&state=...
```

1. Compares returned `state` vs session value — mismatch → redirect with error
2. Decodes state to recover `org_id` and `flow_uuid`
3. Calls `Socialite::driver('google')->stateless()->user()` to exchange code for tokens
4. Writes `access_token`, `refresh_token`, `google_email`, `connected_at`, `token_expires_at` into `organizations.metadata`
5. Redirects back to the flow edit page

#### `disconnect($request)`

```
DELETE /automation/meta-flows/google/disconnect
```

Removes the `google_oauth` key from metadata. Does **not** revoke the token at Google — the token simply becomes orphaned.

---

### 10.3 `GoogleSheetsService`

All communication with the Google Sheets REST API is done here via Guzzle (not the `google/apiclient` SDK).

#### `fromOrganization(int $orgId): self`

Static factory — loads `access_token` and `refresh_token` from `organizations.metadata.google_oauth` and returns a new service instance.

#### `extractSpreadsheetId(string $url): ?string`

Regex parses the spreadsheet ID from any Google Sheets URL format:
```
https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit#gid=0
```
Returns `null` if the URL is not a valid Sheets URL.

#### `getSheetHeaders(string $spreadsheetId, string $tab): array`

```
GET https://sheets.googleapis.com/v4/spreadsheets/{id}/values/{tab}!1:1
```

Fetches only the first row and returns it as a plain array of non-empty strings. This is used:
1. In the UI when the user clicks "Fetch Sheet Columns"
2. In `PopulateGoogleSheetJob` to determine the column insertion order

#### `appendRow(string $spreadsheetId, string $tab, array $values): void`

```
POST https://sheets.googleapis.com/v4/spreadsheets/{id}/values/{tab}:append
     ?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS
Body: { "values": [[ "val1", "val2", ... ]] }
```

- `USER_ENTERED` — Google parses the values (detects dates, numbers)
- `INSERT_ROWS` — always inserts a new row, never overwrites existing data
- On a `401` response: calls `refreshAccessToken()` then retries **once**

#### `refreshAccessToken()` (private)

```
POST https://oauth2.googleapis.com/token
     { client_id, client_secret, refresh_token, grant_type: "refresh_token" }
```

On success:
1. Updates `$this->accessToken` in memory
2. Persists new `access_token` and `token_expires_at` to `organizations.metadata`

---

### 10.4 `PopulateGoogleSheetJob`

**Queue:** `default` | **Tries:** 3 | **Backoff:** 30s → 120s → 300s

```php
public function __construct(
    private readonly int $metaFlowResponseId,
    private readonly int $organizationId,
)
```

#### `handle()` — step by step

```
1. Load MetaFlowResponse → bail if not found or response_json is null

2. Load MetaFlow → bail if:
   - not found
   - google_sheet_enabled is false
   - google_sheet_url is empty

3. Extract spreadsheetId from google_sheet_url via extractSpreadsheetId()
   → bail if URL is invalid

4. Load Contact (nullable — metadata fields use ?-> safe navigation)

5. Parse response_json string into array:
   $parsedJson = json_decode($response->response_json, true)

6. Build columnValues dictionary  { column_header → value }:
   foreach (google_sheet_mapping as $map):
     $columnValues[$map['sheet_column']] = resolve($map['flow_field'])

   Special flow_field tokens:
     __timestamp     → now()->toIso8601String()
     __contact_phone → $contact->phone
     __contact_name  → "{first_name} {last_name}"
     __flow_token    → $response->flow_token
     anything else   → $parsedJson[$field] ?? ''

7. Fetch real column order from sheet:
   $headers = GoogleSheetsService::getSheetHeaders($spreadsheetId, $tab)

8. Build row array ordered by the sheet's actual column positions:
   $row = array_map(fn($header) => $columnValues[$header] ?? '', $headers)

9. GoogleSheetsService::appendRow($spreadsheetId, $tab, $row)
```

**Why step 7 is critical:** The `google_sheet_mapping` array is ordered by how the user configured the mapping in the UI, which may differ from the sheet's column order. Fetching the real headers and mapping by name ensures values always land in the correct column regardless of mapping or column order.

#### `failed(\Throwable $exception)`

Called by Laravel after all retries are exhausted. Logs a permanent failure with `response_id` and the error message.

---

### 10.5 Field Mapping Configuration

The `google_sheet_mapping` column stores an array of objects:

```json
[
  { "flow_field": "__timestamp",  "sheet_column": "Submitted At" },
  { "flow_field": "__contact_phone", "sheet_column": "Phone" },
  { "flow_field": "full_name",    "sheet_column": "Name" },
  { "flow_field": "email",        "sheet_column": "Email Address" },
  { "flow_field": "rating",       "sheet_column": "Rating" }
]
```

**`flow_field`** is either:
- A special token: `__timestamp`, `__contact_phone`, `__contact_name`, `__flow_token`
- A form field `name` from the Flow JSON (e.g. `full_name`, `email`)

**`sheet_column`** is the exact text of the column header in row 1 of the sheet.

If a sheet column has no matching `flow_field` mapped to it, the job writes an empty string for that cell (column is preserved in order, just blank).

---

### 10.6 `extractFlowFields()` — Parsing the Flow JSON

```php
// MetaFlow model
public function extractFlowFields(): array
```

Traverses `asset_content` JSON to extract all form input definitions:

```
screens[]
  → layout.children[]
      → if child.type === 'Form': iterate child.children[]
        else: treat child as the item
      → if item.type in [TextInput, TextArea, Dropdown, CheckboxGroup,
                          RadioButtonsGroup, DatePicker]:
          → collect { name, label, type }
```

Returns `[{name, label, type}]`. Used by `getFlowFields()` endpoint to populate the mapping UI.

---

## 11. Frontend — View.vue

The flow editor page (`modules/MetaFlows/Pages/User/View.vue`) is a three-column layout:

```
[Left sidebar]  |  [Center: builder + raw JSON]  |  [Right: phone preview]
```

### Left Sidebar Panels

1. **Metadata panel** — categories, response count, last updated
2. **How-to guide** — static tips
3. **Deletion warning** — amber info box
4. **Google Sheets panel** — collapsible (described below)

### Google Sheets Panel State

```js
googleStatus     // { connected: bool, google_email: string|null }
sheetUrl         // bound to input, initialized from props.flow.google_sheet_url
sheetTab         // bound to input, default 'Sheet1'
sheetMapping     // saved mapping array from props.flow.google_sheet_mapping
sheetEnabled     // boolean toggle
sheetHeaders     // array of column names fetched from the sheet
flowFields       // array of {name, label, type} from the flow's JSON
showSheetSection // controls collapsible visibility
savingSheet      // loading state for Save button
loadingHeaders   // loading state for Fetch button
mappingFor       // { [fieldName]: selectedSheetColumn } — the working mapping state
```

```js
// Computed: metadata fields prepended before flow form fields
allFlowFields = [...metadata_fields, ...flowFields]
```

### `onMounted()`

Two things happen immediately when the page loads:

1. **Check Google connection status:**
   ```
   GET /automation/meta-flows/google/status
   → sets googleStatus.connected and googleStatus.google_email
   ```

2. **Restore saved mapping:** If `props.flow.google_sheet_mapping` exists, iterate it and populate `mappingFor` so the selects show saved selections when the panel is opened.

### `openSheetSection()`

Toggles `showSheetSection`. On first open (when `flowFields` is empty):
```
GET /automation/meta-flows/{uuid}/flow-fields
→ sets flowFields
→ initializes any new field names in mappingFor with ''
```

### `fetchHeaders()`

```
GET /automation/meta-flows/{uuid}/sheet-headers
    ?google_sheet_url={url}&google_sheet_tab={tab}
→ sets sheetHeaders
→ auto-maps fields where field.label matches a header (case-insensitive)
```

Triggered on URL blur (if tab is also filled) and on tab blur.

### `saveSheetConfig()`

```js
// Build mapping array from mappingFor (only fields with a selected column)
const mapping = allFlowFields
    .filter(f => mappingFor[f.name])
    .map(f => ({ flow_field: f.name, sheet_column: mappingFor[f.name] }))

PUT /automation/meta-flows/{uuid}/sheet-config
    { google_sheet_url, google_sheet_tab, google_sheet_mapping, google_sheet_enabled }
```

---

## 12. API Routes Reference

All routes under `/automation/meta-flows` require `auth:user` middleware.

### Flow Management

| Method | Path | Controller | Action |
|--------|------|-----------|--------|
| GET | `/automation/meta-flows` | MetaFlowController | `index` — list page |
| POST | `/automation/meta-flows` | MetaFlowController | `store` — create flow |
| GET | `/automation/meta-flows/list` | MetaFlowController | `list` — JSON list for chat picker |
| POST | `/automation/meta-flows/pull` | MetaFlowController | `pull` — bulk sync from Meta |
| GET | `/automation/meta-flows/{uuid}` | MetaFlowController | `view` — editor page |
| PUT | `/automation/meta-flows/{uuid}` | MetaFlowController | `update` — upload JSON |
| DELETE | `/automation/meta-flows/{uuid}` | MetaFlowController | `destroy` — delete flow |
| POST | `/automation/meta-flows/{uuid}/publish` | MetaFlowController | `publish` |
| POST | `/automation/meta-flows/{uuid}/deprecate` | MetaFlowController | `deprecate` |
| POST | `/automation/meta-flows/{uuid}/sync` | MetaFlowController | `sync` |
| GET | `/automation/meta-flows/{uuid}/responses` | MetaFlowController | `responses` |

### Google Sheets

| Method | Path | Controller | Action |
|--------|------|-----------|--------|
| GET | `/automation/meta-flows/google/status` | MetaFlowController | `googleStatus` |
| GET | `/automation/meta-flows/google/connect` | GoogleOAuthController | `connect` |
| GET | `/automation/meta-flows/google/callback` | GoogleOAuthController | `callback` |
| DELETE | `/automation/meta-flows/google/disconnect` | GoogleOAuthController | `disconnect` |
| GET | `/automation/meta-flows/{uuid}/flow-fields` | MetaFlowController | `getFlowFields` |
| GET | `/automation/meta-flows/{uuid}/sheet-headers` | MetaFlowController | `getSheetHeaders` |
| PUT | `/automation/meta-flows/{uuid}/sheet-config` | MetaFlowController | `saveSheetConfig` |

**Route ordering matters:** All `/google/*` and `/{uuid}/flow-fields` etc. routes are registered **before** the `/{uuid}` catch-all routes to prevent Laravel matching `google` or `list` as a UUID.

---

## 13. Local Testing Guide

### Environment Setup

Add to `.env`:
```env
GOOGLE_SHEETS_CLIENT_ID=your_client_id
GOOGLE_SHEETS_CLIENT_SECRET=your_client_secret
GOOGLE_SHEETS_REDIRECT_URI=http://127.0.0.1:8000/automation/meta-flows/google/callback
```

Create OAuth credentials at [Google Cloud Console](https://console.cloud.google.com/apis/credentials):
- Application type: **Web application**
- Authorized redirect URI: `http://127.0.0.1:8000/automation/meta-flows/google/callback`
- Enable **Google Sheets API** for the project

### Testing the Full Data Flow (Postman)

**Step 1** — Create a stub `MetaFlowResponse` via Tinker:

```php
$flow    = \Modules\MetaFlows\Models\MetaFlow::where('uuid', 'YOUR_UUID')->first();
$contact = \App\Models\Contact::where('phone', '923134711699')->first();

$token = \Illuminate\Support\Str::uuid()->toString();

\Modules\MetaFlows\Models\MetaFlowResponse::create([
    'organization_id' => $flow->organization_id,
    'meta_flow_id'    => $flow->id,
    'contact_id'      => $contact->id,
    'flow_token'      => $token,
    'response_json'   => null,
]);

echo $token;
```

**Step 2** — Send mock `nfm_reply` webhook via Postman:

```
POST http://127.0.0.1:8000/webhook/whatsapp/{your_identifier}
Content-Type: application/json
```

```json
{
  "entry": [{
    "id": "waba_id",
    "changes": [{
      "value": {
        "messaging_product": "whatsapp",
        "metadata": {
          "display_phone_number": "966564419824",
          "phone_number_id": "PHONE_NUMBER_ID"
        },
        "contacts": [{"profile": {"name": "Talha"}, "wa_id": "923134711699"}],
        "messages": [{
          "from": "923134711699",
          "id": "wamid.test123",
          "timestamp": "1700000000",
          "type": "interactive",
          "interactive": {
            "type": "nfm_reply",
            "nfm_reply": {
              "response_json": "{\"flow_token\":\"PASTE_TOKEN_HERE\",\"full_name\":\"Talha Ahmed\",\"email\":\"talha@example.com\",\"rating\":\"5\",\"comments\":\"Great service!\"}",
              "body": "Sent",
              "name": "flow"
            }
          }
        }]
      },
      "field": "messages"
    }]
  }]
}
```

> `response_json` must be a **JSON string** (stringified) — this is how Meta sends it.

**Step 3** — Process the queue:

```bash
php artisan queue:work --once   # ProcessWebhookJob
php artisan queue:work --once   # PopulateGoogleSheetJob
```

**Step 4** — Verify in logs:

```bash
tail -f storage/logs/laravel.log | grep -E "nfm_reply|PopulateGoogle|row appended"
```

### Skipping the Webhook (Direct Job Dispatch)

```php
// In Tinker — triggers the sheet write directly
\App\Jobs\PopulateGoogleSheetJob::dispatch(RESPONSE_ID, ORG_ID);
php artisan queue:work --once
```

---

## 14. Key Design Decisions

### Why tokens are stored in `organizations.metadata`

The `metadata` column is a JSON blob already used for WhatsApp credentials. Adding `google_oauth` there avoids schema migrations for credentials that may be added/removed per org and keeps all third-party credentials in one place.

### Why `nfm_reply` is non-blocking

`processMetaFlowResponse()` inside `ProcessWebhookJob` is wrapped in try/catch. A MetaFlows failure (bad token, no stub, etc.) logs a warning but never interrupts the rest of message processing (contact creation, conversation storage, bot replies).

Similarly, dispatching `PopulateGoogleSheetJob` is wrapped in try/catch inside `handleFlowResponse()` — a sheet misconfiguration cannot break webhook processing.

### Why the job fetches sheet headers at runtime

The column order in the sheet is the source of truth, not the mapping array order. By fetching headers at job execution time:
- Users can reorder sheet columns without reconfiguring the mapping
- The mapping UI only needs to track "which field → which column name", not column positions

### Why `access_type=offline` + `prompt=consent` on every connect

`access_type=offline` requests a refresh token. Google only issues a refresh token on the **first** authorization — subsequent authorizations without `prompt=consent` return no refresh token. Forcing `prompt=consent` every time ensures a fresh refresh token is always obtained when the user reconnects.

### Why `resolveFirstScreenId` reads from `asset_content`

Meta returns error `#131009 Specified screen X is not allowed as first screen` if the screen name doesn't match the flow's actual first screen. Since the first screen ID is user-defined in the flow JSON, reading it from `asset_content` is the only reliable way to get it without an extra API call on every send.
