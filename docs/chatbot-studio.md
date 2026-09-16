# Chatbot Studio

Admin route: `/admin/chatbot-studio`.

The tool selects an organization, keeps a private draft and conversation for the
signed-in admin, accepts text, files or a Chatberry simulator URL, and compiles a
reviewed blueprint into native flows and trigger groups.

## Free Cloud AI Setup

Chatbot Studio uses the **Gemini Developer API**, not a local model. A separate
Google AI Studio account/project and API key are required. The application does
not create a Google account or enable billing.

1. Open <https://aistudio.google.com/api-keys> and create a key in a **Free Tier**
   project. Check that Cloud Billing is disabled for that project.
2. Set these variables in the server's private `.env` file, not in source control:

   ```dotenv
   CHATBOT_STUDIO_GEMINI_API_KEY=your_private_key
   CHATBOT_STUDIO_FREE_TIER_CONFIRMED=true
   CHATBOT_STUDIO_AI_MODEL=gemini-3.8-flash
   ```

3. Run `php artisan config:clear` and restart the Chatbot Studio worker.

`FREE_TIER_CONFIRMED` is the operator's acknowledgement, **not** a technical check
of Google's billing status. The same model can be billed when the key belongs to
a billing-enabled project. Keep billing disabled to remain on the free tier.

The free service has quotas and regional availability restrictions. A quota
error preserves the draft and stops generation; the tool does not switch to a
paid model, another provider or a local model. There is no promise of unlimited
free usage or permanent provider pricing. Check the current project quota in AI
Studio. The default model, `gemini-3.6-flash`, and `gemini-2.5-flash` are
allowlisted. Free standard tiers are documented, but availability can differ
by project. A live diagnostic on 2026-09-13 returned high-demand HTTP 503 for
3.8, new-user unavailability HTTP 404 for 2.5, and successful generation for
3.6. Select 3.6 explicitly with `CHATBOT_STUDIO_AI_MODEL=gemini-3.6-flash` if
needed; no automatic model fallback is performed. Rebuild configuration cache
and restart the dedicated worker after changing the model.

**Privacy:** Google's unpaid-service terms permit use of submitted content to
improve its products. Do not submit customer secrets, passwords, sensitive
personal data or confidential documents to this free service. Inputs are sent
server-to-server over HTTPS. API keys are not exposed to the browser or included
in URLs. The feature does not send database records or contact data to Gemini;
it sends only the admin's source, instructions and previous draft.

Sources:
- <https://ai.google.dev/gemini-api/docs/pricing>
- <https://ai.google.dev/gemini-api/docs/billing>
- <https://ai.google.dev/gemini-api/docs/available-regions>
- <https://ai.google.dev/gemini-api/terms>

## Installation

Apply the new migration, build the frontend and run the dedicated queue worker:

```shell
php artisan migrate --path=database/migrations/2026_09_12_180000_create_chatbot_studio_drafts_table.php
php artisan migrate --path=database/migrations/2026_09_16_180000_add_generation_state_to_chatbot_studio_drafts.php
npm run build
php artisan queue:work chatbot_studio --queue=chatbot-studio --tries=1 --timeout=600
```

The existing `jobs` table is required. Use a process manager to keep the worker
running on the server. Its database connection has a 660-second retry window;
each job allows two 240-second AI requests by default. Queue cancellation
invalidates late results without stopping other organizations' work.

Temporary HTTP 500/502/503/504 responses are retried up to twice, with exponential
backoff and jitter, using the same model and source. All attempts share the
original 240-second request budget. HTTP 429 and permanent request errors are
not retried. Persistent service outages preserve the draft and ask the admin to
try again later; neither a paid provider nor a local fallback is selected.

## Incomplete Generation Recovery

The API now receives a JSON schema and Gemini 3 requests use its recommended
temperature of 1.0. Exact source preservation remains a prompt requirement and
must be reviewed by the admin; a valid schema alone cannot guarantee fidelity.
An incomplete response reports its actual finish/block reason. Server logs
record only the model, reason and token counts, not prompts, output or keys.

Sources over 60 KB start with a compact routing inventory. Smaller sources use
the existing full-blueprint path and switch to the inventory on MAX_TOKENS.
Content is generated in batches of at most three screens. An overflowing batch
is split; an individual screen or inventory can retry with an output limit up
to 65,536 tokens. These limits apply only to the allowlisted models. Persistent
single-screen overflow is an explicit failure requiring source revision.

Each successful stage is checkpointed in private `generation_state` and the
next stage is enqueued transactionally. Each job keeps the existing 600-second
timeout and 660-second retry window; large bots use multiple jobs, not a longer
unbounded job. Version and step checks reject stale/duplicate results. Every
planned screen and portal must be present and pass the native compiler before
the new blueprint replaces the draft. Partial batches are never publishable.

The failed-draft **Retry generation** button resumes the same checkpoint and
source without appending another copy of the simulator. It requires ownership,
edit permission and the current draft version. Clear unsent edits before using
it; sending a new revision intentionally starts a new generation. A model
change during recovery also requires a new revision. Quota and safety failures
do not silently retry or switch providers. Completed batches survive failures.

### Production Upgrade

Back up the database and deploy all changed PHP files, routes, the migration,
and matching frontend build together. Pause access to Studio while migrating
and stop only its dedicated Supervisor group first. Do not stop email workers.

Recovery release file checklist (merge existing files with production changes):

```text
app/Services/ChatbotStudio/StudioAiService.php
app/Services/ChatbotStudio/BlueprintGenerator.php
app/Services/ChatbotStudio/BlueprintSchema.php                     (new)
app/Services/ChatbotStudio/BlueprintRecovery.php                   (new)
app/Services/ChatbotStudio/IncompleteGenerationException.php       (new)
app/Jobs/GenerateChatbotStudioDraft.php
app/Models/ChatbotStudioDraft.php
app/Http/Controllers/Admin/ChatbotStudioController.php
routes/web.php                                                   (retry route)
resources/js/Pages/Admin/ChatbotStudio/Index.vue
database/migrations/2026_09_16_180000_add_generation_state_to_chatbot_studio_drafts.php
scripts/verify_chatbot_studio_generation.php                      (optional CLI smoke test)
public/build/                                                   (complete matching build)
```

Rebuild Composer's optimized autoloader in the release if production uses an
authoritative classmap, so the new recovery classes are included.

```shell
sudo supervisorctl stop 'chatberry-chatbot-studio:*'
# Deploy the reviewed release, including the frontend build, then:
cd /var/www/dashboard.chatberry.net
sudo -u www-data php artisan migrate --path=database/migrations/2026_09_16_180000_add_generation_state_to_chatbot_studio_drafts.php --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:cache
sudo supervisorctl start 'chatberry-chatbot-studio:*'
```

Keep the existing worker command, `numprocs=1` and `stopwaitsecs=720`. Apply
the new migration before the updated controller or worker handles requests.
Build with `npm ci` and `npm run build` in the release/CI environment; never
mix old and new hashed assets. Reload PHP-FPM if the deployment uses OPcache
without timestamp validation. Do not copy local `.env` or database contents.

Before reopening Studio, verify `/retry` is registered and run the synthetic
cloud check below. This makes a routing inventory plus content batches, checks
exact synthetic messages and links, and compiles in memory without DB writes:

```shell
php artisan route:list --path=admin/chatbot-studio
php scripts/verify_chatbot_studio_generation.php --model=gemini-3.6-flash
php scripts/verify_chatbot_studio_generation.php --model=gemini-3.6-flash --direct
```

For rollback, stop the Studio worker and restore the previous code/assets;
keep the additive checkpoint column and preserve drafts. Pause pending recovery
jobs until this release is restored; old workers cannot process checkpoint
stages correctly. Never roll back by deleting customer flows or dropping drafts.

Admin permissions use `organizations.view` for access and `organizations.edit`
for generation, editing, cancellation and publication. Drafts are private to
their creator. Existing admin authentication remains in use.

## Workflow

- Select the organization by name or ID. It cannot change inside an existing draft.
- Enter content, attach a file or paste a simulator URL. A URL-only chat message
  is recognized as a simulator link too.
- Review all screens, messages, questions and portal destinations. Continue the
  conversation to revise the bot, or edit the structured fields/JSON directly.
- Save manual changes before another generation or publication. Conflicting
  versions are rejected; reload instead of overwriting another browser's edits.
- Use the preview for an in-browser walkthrough. Preview answers are local only;
  this is not an actual WhatsApp send or a booking/payment action.
- Resolve every open issue, then explicitly confirm publication for the selected
  organization. Publishing is transactional and idempotent for that draft.

## Native Flow Mapping

- One screen becomes one flow. Every reply choice uses a portal to its target
  screen's flow and an exact `new_message` keyword trigger.
- The default start keyword is `000`. No greeting, new-contact or away triggers.
- Up to three replies use interactive buttons. Four to ten use a list.
- Labels are limited to 20 UTF-16 units; longer labels must be shortened.
- Reused labels must have the same destination; conflicting existing enabled
  exact triggers block publication instead of being overwritten.
- Questions use native variables and `success|<node-id>` edges. Text, questions,
  buttons, lists, website CTA buttons and portals are currently compilable.
- UUIDs are fresh and numeric IDs are generated by the database. All portal
  numeric/UUID links are resolved inside the same organization and transaction.
- Unsupported integrations, media or uncertain source details are reported as
  issues. They must not silently turn into fabricated successful submissions.

Input formats: TXT, MD, JSON, HTML, DOCX and text-based PDF. Upload limit: 2 MB;
extracted source limit: 180 KB of UTF-8. Scanned PDFs require extracted text.
Simulator URLs are restricted to HTTPS `chatbot.chatberry.click` and redirects
are disabled. Inline JavaScript is read as inert text, never executed.

## Verification

```shell
php vendor/bin/pest tests/Feature/ChatbotStudioTest.php
php scripts/inspect_chatbot_studio.php
```

Feature tests use an isolated in-memory SQLite database and mocked AI/network
responses. The inspector checks the real source link and configured AI endpoint
without publishing flows. A live cloud generation still requires a valid key.

For production diagnosis without reading customer sources or modifying drafts:

```shell
php scripts/diagnose_chatbot_studio.php --probe
php scripts/diagnose_chatbot_studio.php --probe --model=gemini-3.6-flash
```

The CLI-only diagnostic reads configuration, checks model metadata, and with
`--probe` makes one short synthetic generation request. It prints sanitized
provider errors, never the API key. `--model` changes only that diagnostic
process, not `.env` or the running worker. Successful probes do not establish
that an entire simulator can be generated faithfully.

Recovery release verification (2026-09-16): 49 focused tests passed, frontend
production build passed, and both direct and staged synthetic cloud generation
preserved five screens with exact text/routing and compiled six triggers without
database writes. A transient 503 occurred on the direct smoke test and the
bounded retry recovered. The broader existing AdminPermissionTest suite could
not initialize Laravel's config container in this Windows test runner (four
setup failures); Studio's own ownership, permission, cancellation and retry
tests passed. Browser screenshot verification was unavailable because the CUA
runtime could not initialize its assets. The reported production simulator has
not been replayed against this release; perform that acceptance test in staging.
