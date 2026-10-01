# Chatbot Studio: DeepSeek deployment

DeepSeek is an explicit, paid alternative to Gemini. Existing installations stay
on Gemini unless `CHATBOT_STUDIO_AI_PROVIDER=deepseek` is set. There is no
automatic fallback to another model/provider or local service. Paid consent is
separate from Gemini's free-tier confirmation.

## Files

Deploy these files together, preserving the prior Studio release:

- `config/chatbot_studio.php`
- `app/Services/ChatbotStudio/StudioAiService.php`
- `app/Services/ChatbotStudio/TokenUsageTracker.php`
- `app/Jobs/GenerateChatbotStudioDraft.php`
- `scripts/diagnose_chatbot_studio.php`
- `resources/js/Pages/Admin/ChatbotStudio/Index.vue`
- `lang/ar.json`
- The entire matching `public/build/` directory, including `manifest.json`.

No new migration or dependency is required. The existing token-usage migration
must already be installed. Build with `npm ci` and `npm run build`, or deploy the
complete prebuilt assets. Back up the current release and database first.

## Private environment

Revoke any key pasted into a conversation and create a replacement. Store it only
in the server's private `.env`, never in Vue, Git or screenshots. Do not upload
the development `.env` over production's configuration.

```dotenv
CHATBOT_STUDIO_AI_PROVIDER=deepseek
CHATBOT_STUDIO_AI_MODEL=deepseek-flash
CHATBOT_STUDIO_DEEPSEEK_API_KEY=YOUR_REPLACEMENT_KEY
CHATBOT_STUDIO_PAID_USAGE_CONFIRMED=true
CHATBOT_STUDIO_OUTPUT_TOKENS=24000
CHATBOT_STUDIO_REQUEST_TIMEOUT=240
```

Replace the key placeholder. Gemini settings may remain; DeepSeek never sends
the Gemini key. Allowed DeepSeek models: `deepseek-flash`, `deepseek-v4-pro`.
Change provider AND model: an explicit old Gemini model is not overridden.

## Deployment

Pause new Studio generation and let active jobs finish. Stop only the Studio
worker, deploy the matching PHP/assets and update `.env`, then refresh caches:

```shell
cd /var/www/dashboard.chatberry.net
sudo supervisorctl stop 'chatberry-chatbot-studio:*'
# Deploy application/assets and update .env here.
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:clear
sudo -u www-data php scripts/diagnose_chatbot_studio.php --list-models
sudo -u www-data php scripts/diagnose_chatbot_studio.php --probe
sudo supervisorctl start 'chatberry-chatbot-studio:*'
sudo supervisorctl status 'chatberry-chatbot-studio:*'
```

Use your actual Supervisor group name. Do not restart email workers or use
`restart all`. Queue settings and the 600-second timeout remain unchanged. If
OPcache timestamp validation is disabled, gracefully reload the actual web PHP
service. Refresh the browser for updated assets.

`--list-models` is offline. `--probe` performs a model lookup followed by one small
synthetic generation, capped at 256 output tokens for DeepSeek. It sends no
source documents and publishes nothing. Generation is billable; do not loop it.

## Errors and acceptance

- HTTP 401: check the DeepSeek key and configuration cache.
- HTTP 402: insufficient API balance. Review/top up your account yourself;
  the application never purchases credit or silently switches to Gemini.
- HTTP 429: wait before retrying; this is not Gemini's free-tier reset.
- HTTP 5xx: up to three same-model attempts in one bounded timeout.
  JSON repair and staged recovery can create additional billable requests.
- TIMEOUT/DNS/TLS/CONNECT: check server connectivity; never disable TLS checks.

After changing provider/model, start a new draft or explicit new revision. Old
staged checkpoints cannot resume across providers/models. Published flows remain
unchanged. Review every branch and open issue in a test organization before
publishing. JSON mode includes the schema in the prompt, followed by strict
local validation, repair and normalization; JSON mode is not schema enforcement.

Requests are text-only with thinking disabled. Provider reasoning is not exposed.
Token counts remain per-draft aggregates across attempts, without double-counting
cached input or reported reasoning. Missing usage is unknown, not zero. Counts
are not an invoice; pricing and peak/cache rates are provider-controlled.

## Verification on 2026-10-01

112 focused tests passed (762 assertions), using mocked responses. A live model
lookup returned HTTP 200 and confirmed `deepseek-flash` access. The single live
synthetic generation returned HTTP 402 (insufficient balance). Successful live
generation and complete-document fidelity remain unverified. Paid usage was
authorized; no credit was purchased and production was not modified.
The production asset build and whitespace check succeeded. The local server is
available at `http://127.0.0.1:8127/admin/chatbot-studio`; browser verification
reached the login redirect only, so authenticated visual acceptance is still due.

Official references checked on 2026-10-01:

- https://api-docs.deepseek.com/quick_start/pricing/
- https://api-docs.deepseek.com/guides/json_mode/
- https://api-docs.deepseek.com/guides/thinking_mode/
- https://api-docs.deepseek.com/api/list-models/
